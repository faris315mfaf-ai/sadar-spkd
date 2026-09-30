/**
 * Sign-up onboarding: face registration (camera + face-api.js) and a location check.
 */

import { isTimeoutError, sleep, withTimeout } from './async-timeout.js';
import { csrfFetch } from './csrf-fetch.js';
import { createDetectorOptions, detectFaces, loadRecognitionModel, waitForFaceApi } from './face-api-runtime.js';
import { distanceMeters, formatDistance } from './geo-distance.js';

// A face narrower than this share of the frame is too far away to make a reliable reference.
const MIN_FACE_WIDTH_RATIO = 0.2;
// Larger frames only cost memory and upload time on phones.
const MAX_CAPTURE_SIZE = 720;
const CAMERA_PERMISSION_TIMEOUT_MS = 60000;
const VIDEO_PLAY_TIMEOUT_MS = 8000;
const SAVE_TIMEOUT_MS = 60000;

function hasFrames(video) {
    return video.videoWidth > 0 && video.videoHeight > 0 && video.readyState >= 2;
}

/**
 * Starts the preview and waits for real frames: on iPhone, play() can resolve
 * while the element still shows nothing, which made every capture a black photo.
 */
async function playPreview(video, timeoutMs = 6000) {
    video.muted = true;
    video.playsInline = true;

    try {
        await withTimeout(video.play(), VIDEO_PLAY_TIMEOUT_MS, 'Memutar kamera');
    } catch (error) {
        console.warn('Preview kamera belum berjalan:', error);
    }

    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        if (hasFrames(video)) {
            return true;
        }
        await sleep(100);
    }

    return hasFrames(video);
}

function setStatus(element, message, tone = 'info') {
    const tones = {
        info: 'text-gray-500 dark:text-gray-400',
        error: 'text-red-600 dark:text-red-400',
        success: 'text-emerald-600 dark:text-emerald-400',
    };

    element.textContent = message;
    element.className = `min-h-5 text-center text-sm ${tones[tone] ?? tones.info}`;
}

function toggle(element, visible) {
    element.classList.toggle('hidden', !visible);
    element.classList.toggle('inline-flex', visible && element.tagName === 'BUTTON');
}

function cameraErrorMessage(error) {
    if (!window.isSecureContext) {
        return 'Kamera hanya bisa dipakai lewat HTTPS atau localhost.';
    }

    switch (error?.name) {
        case 'NotAllowedError':
        case 'SecurityError':
            return 'Izin kamera ditolak. Izinkan akses kamera di pengaturan browser, lalu muat ulang halaman.';
        case 'NotFoundError':
        case 'OverconstrainedError':
            return 'Kamera tidak ditemukan di perangkat ini.';
        case 'NotReadableError':
            return 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi tersebut lalu coba lagi.';
        default:
            return 'Kamera tidak bisa dibuka. Coba gunakan Chrome, Edge, atau Safari terbaru.';
    }
}

export function initFaceRegistration(root) {
    const video = root.querySelector('#onboarding-face-video');
    const preview = root.querySelector('#onboarding-face-preview');
    const guide = root.querySelector('#onboarding-face-guide');
    const placeholder = root.querySelector('#onboarding-face-placeholder');
    const status = root.querySelector('#onboarding-face-status');
    const startButton = root.querySelector('#onboarding-face-start');
    const captureButton = root.querySelector('#onboarding-face-capture');
    const saveButton = root.querySelector('#onboarding-face-save');
    const retakeButton = root.querySelector('#onboarding-face-retake');

    let stream = null;
    let captured = null;

    const stopCamera = () => {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
    };

    const showLiveView = () => {
        captured = null;
        toggle(video, true);
        toggle(preview, false);
        guide.classList.remove('hidden');
        guide.classList.add('flex');
        placeholder.classList.add('hidden');
        toggle(captureButton, true);
        toggle(saveButton, false);
        toggle(retakeButton, false);
    };

    startButton.addEventListener('click', async () => {
        startButton.disabled = true;
        setStatus(status, 'Membuka kamera...');

        if (!navigator.mediaDevices?.getUserMedia) {
            setStatus(status, cameraErrorMessage(null), 'error');
            startButton.disabled = false;
            return;
        }

        // Start loading the recognizer while the camera permission prompt is open.
        const modelsReady = waitForFaceApi().then((ready) => {
            if (!ready) {
                throw new Error('face-api.js tidak termuat');
            }
            return loadRecognitionModel();
        });
        modelsReady.catch(() => { });

        stopCamera();
        try {
            const pending = navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 960 }, height: { ideal: 720 } },
                audio: false,
            });
            stream = await withTimeout(pending, CAMERA_PERMISSION_TIMEOUT_MS, 'Membuka kamera').catch((error) => {
                if (isTimeoutError(error)) {
                    pending.then((late) => late.getTracks().forEach((track) => track.stop())).catch(() => { });
                }
                throw error;
            });
        } catch (error) {
            console.error('Gagal membuka kamera:', error);
            setStatus(status, isTimeoutError(error)
                ? 'Kamera belum diizinkan. Tekan "Izinkan" saat browser meminta akses kamera, lalu coba lagi.'
                : cameraErrorMessage(error), 'error');
            startButton.disabled = false;
            return;
        }

        // The element must be visible before playing, or iPhone renders no frames.
        toggle(startButton, false);
        showLiveView();
        captureButton.disabled = true;
        video.srcObject = stream;

        if (!(await playPreview(video))) {
            stopCamera();
            toggle(startButton, true);
            startButton.disabled = false;
            setStatus(status, 'Gambar kamera belum muncul. Tekan "Aktifkan Kamera" lagi, atau muat ulang halaman.', 'error');
            return;
        }

        setStatus(status, 'Memuat pengenal wajah...');

        try {
            await modelsReady;
        } catch (error) {
            console.error(error);
            setStatus(status, 'Pengenal wajah gagal dimuat. Periksa koneksi internet lalu muat ulang halaman.', 'error');
            return;
        }

        captureButton.disabled = false;
        setStatus(status, 'Posisikan wajah di dalam oval, lalu tekan "Ambil Foto".');
    });

    captureButton.addEventListener('click', async () => {
        if (!stream?.active || !hasFrames(video)) {
            setStatus(status, 'Kamera belum siap. Tunggu sebentar lalu coba lagi.', 'error');
            if (stream?.active) {
                playPreview(video).catch(() => { });
            }
            return;
        }

        captureButton.disabled = true;
        setStatus(status, 'Memeriksa wajah... (bisa beberapa detik)');

        const scale = Math.min(1, MAX_CAPTURE_SIZE / Math.max(video.videoWidth, video.videoHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

        let detections = [];
        try {
            detections = await detectFaces(canvas, createDetectorOptions());
        } catch (error) {
            console.error('Deteksi wajah gagal:', error);
            captureButton.disabled = false;
            setStatus(status, 'Pemeriksaan wajah terlalu lama. Tutup aplikasi lain, lalu tekan "Ambil Foto" lagi.', 'error');
            return;
        }

        captureButton.disabled = false;

        if (detections.length === 0) {
            setStatus(status, 'Wajah tidak terdeteksi. Pastikan wajah terlihat jelas dan cahaya cukup.', 'error');
            return;
        }

        if (detections.length > 1) {
            setStatus(status, 'Terdeteksi lebih dari satu wajah. Pastikan hanya wajah Anda yang terlihat.', 'error');
            return;
        }

        if (detections[0].detection.box.width < canvas.width * MIN_FACE_WIDTH_RATIO) {
            setStatus(status, 'Wajah terlalu jauh. Dekatkan wajah ke kamera.', 'error');
            return;
        }

        captured = {
            photo: canvas.toDataURL('image/jpeg', 0.9),
            descriptor: Array.from(detections[0].descriptor),
        };

        preview.src = captured.photo;
        toggle(preview, true);
        toggle(video, false);
        guide.classList.add('hidden');
        guide.classList.remove('flex');
        toggle(captureButton, false);
        toggle(saveButton, true);
        toggle(retakeButton, true);
        setStatus(status, 'Wajah terdeteksi. Periksa foto, lalu tekan "Simpan Wajah".', 'success');
    });

    retakeButton.addEventListener('click', async () => {
        showLiveView();
        captureButton.disabled = true;
        // Safari pauses a hidden preview; restart it before the next capture.
        const ready = stream?.active && (await playPreview(video));
        captureButton.disabled = false;
        setStatus(status, ready
            ? 'Posisikan wajah di dalam oval, lalu tekan "Ambil Foto".'
            : 'Kamera berhenti. Muat ulang halaman lalu aktifkan kamera lagi.', ready ? 'info' : 'error');
    });

    saveButton.addEventListener('click', async () => {
        if (!captured) {
            return;
        }

        saveButton.disabled = true;
        retakeButton.disabled = true;
        setStatus(status, 'Menyimpan wajah...');

        let response = null;
        let body = {};

        const controller = new AbortController();
        const abortTimer = setTimeout(() => controller.abort(), SAVE_TIMEOUT_MS);

        try {
            response = await csrfFetch(root.dataset.storeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    photo: captured.photo,
                    face_descriptor: captured.descriptor,
                    faces_detected: 1,
                }),
                signal: controller.signal,
            });
            if (response === null) {
                return; // CSRF expired: the page reloads itself.
            }
            body = await response.json().catch(() => ({}));
        } catch (error) {
            console.error(error);
        } finally {
            clearTimeout(abortTimer);
        }

        if (response?.ok) {
            stopCamera();
            setStatus(status, body.message || 'Wajah berhasil didaftarkan.', 'success');
            window.setTimeout(() => window.location.assign(body.redirect), 800);
            return;
        }

        if (response?.status === 409 && body.redirect) {
            stopCamera();
            window.location.assign(body.redirect);
            return;
        }

        const firstError = body.errors ? Object.values(body.errors).flat()[0] : null;
        setStatus(status, firstError || body.message || 'Gagal menyimpan. Periksa koneksi lalu coba lagi.', 'error');
        saveButton.disabled = false;
        retakeButton.disabled = false;
    });

    window.addEventListener('pagehide', stopCamera);
}

function locationErrorMessage(error) {
    if (!window.isSecureContext) {
        return 'Lokasi hanya bisa dibaca lewat HTTPS atau localhost.';
    }

    switch (error?.code) {
        case 1:
            return 'Izin lokasi ditolak. Buka pengaturan situs di browser (ikon gembok di samping alamat), pilih Izinkan untuk Lokasi, lalu coba lagi.';
        case 2:
            return 'Lokasi tidak tersedia. Aktifkan GPS/Lokasi di HP lalu coba lagi.';
        case 3:
            return 'Pengambilan lokasi terlalu lama. Pindah ke area terbuka atau dekat jendela, lalu coba lagi.';
        default:
            return 'Browser ini tidak mendukung akses lokasi. Gunakan Chrome, Edge, atau Safari terbaru.';
    }
}

export function initLocationCheck(root) {
    const button = root.querySelector('#onboarding-location-check');
    const result = root.querySelector('#onboarding-location-result');
    let places = [];
    try {
        places = JSON.parse(root.dataset.places || '[]');
    } catch {
        places = [];
    }

    // Text only: location names are typed by admins, so never render them as HTML.
    const show = (title, message, tone) => {
        const tones = {
            success: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200',
            warning: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200',
            error: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300',
        };
        result.className = `rounded-2xl border p-4 text-sm leading-relaxed ${tones[tone]}`;
        result.replaceChildren();

        if (title) {
            const strong = document.createElement('strong');
            strong.textContent = title;
            result.append(strong, ' ');
        }

        result.append(message);
    };

    button.addEventListener('click', () => {
        if (!navigator.geolocation?.getCurrentPosition || !window.isSecureContext) {
            show(null, locationErrorMessage(null), 'error');
            return;
        }

        button.disabled = true;
        button.textContent = 'Membaca lokasi...';

        navigator.geolocation.getCurrentPosition(
            ({ coords }) => {
                button.disabled = false;
                button.textContent = 'Cek Ulang Lokasi';

                const accuracy = `akurasi ±${Math.round(coords.accuracy)} meter`;

                if (places.length === 0) {
                    show('Lokasi terbaca', `(${accuracy}). Akses lokasi sudah siap untuk absensi.`, 'success');
                    return;
                }

                const ranked = places
                    .map((place) => ({
                        place,
                        distance: distanceMeters(coords.latitude, coords.longitude, Number(place.lat), Number(place.lng)),
                    }))
                    .sort((a, b) => a.distance - b.distance);
                const match = ranked.find((row) => row.distance <= Number(row.place.radius));

                if (match) {
                    show(`Anda berada di area ${match.place.name}.`, `Jarak ${formatDistance(match.distance)} dari titik lokasi (${accuracy}). Siap absen.`, 'success');
                } else {
                    const nearest = ranked[0];
                    show('Akses lokasi berhasil,', `tetapi Anda berada di luar area absensi. Lokasi terdekat: ${nearest.place.name}, ${formatDistance(nearest.distance)} dari titik lokasi (radius ${formatDistance(Number(nearest.place.radius))}, ${accuracy}). Absen hanya bisa dilakukan di area tersebut.`, 'warning');
                }
            },
            (error) => {
                button.disabled = false;
                button.textContent = 'Coba Lagi';
                show(null, locationErrorMessage(error), 'error');
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    });
}

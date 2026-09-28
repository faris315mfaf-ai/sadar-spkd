/**
 * Sign-up onboarding: face registration (camera + face-api.js) and a location check.
 */

import { csrfFetch } from './csrf-fetch.js';
import { loadRecognitionModel, waitForFaceApi } from './face-api-runtime.js';
import { distanceMeters, formatDistance } from './geo-distance.js';

// A face narrower than this share of the frame is too far away to make a reliable reference.
const MIN_FACE_WIDTH_RATIO = 0.2;

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

        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'user', width: { ideal: 960 }, height: { ideal: 720 } },
                audio: false,
            });
            video.srcObject = stream;
            await video.play();
        } catch (error) {
            console.error('Gagal membuka kamera:', error);
            setStatus(status, cameraErrorMessage(error), 'error');
            startButton.disabled = false;
            return;
        }

        toggle(startButton, false);
        showLiveView();
        captureButton.disabled = true;
        setStatus(status, 'Memuat pengenal wajah...');

        const faceApiReady = await waitForFaceApi();

        try {
            if (!faceApiReady) {
                throw new Error('face-api.js tidak termuat');
            }
            await loadRecognitionModel();
        } catch (error) {
            console.error(error);
            setStatus(status, 'Pengenal wajah gagal dimuat. Periksa koneksi internet lalu muat ulang halaman.', 'error');
            return;
        }

        captureButton.disabled = false;
        setStatus(status, 'Posisikan wajah di dalam oval, lalu tekan "Ambil Foto".');
    });

    captureButton.addEventListener('click', async () => {
        if (!video.videoWidth) {
            setStatus(status, 'Kamera belum siap. Tunggu sebentar lalu coba lagi.', 'error');
            return;
        }

        captureButton.disabled = true;
        setStatus(status, 'Memeriksa wajah...');

        const canvas = document.createElement('canvas');
        canvas.width = video.videoWidth;
        canvas.height = video.videoHeight;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

        let detections = [];
        try {
            detections = await faceapi
                .detectAllFaces(canvas, new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 }))
                .withFaceLandmarks()
                .withFaceDescriptors();
        } catch (error) {
            console.error('Deteksi wajah gagal:', error);
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

    retakeButton.addEventListener('click', () => {
        showLiveView();
        setStatus(status, 'Posisikan wajah di dalam oval, lalu tekan "Ambil Foto".');
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

        try {
            response = await csrfFetch(root.dataset.storeUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    photo: captured.photo,
                    face_descriptor: captured.descriptor,
                    faces_detected: 1,
                }),
            });
            if (response === null) {
                return; // CSRF expired: the page reloads itself.
            }
            body = await response.json().catch(() => ({}));
        } catch (error) {
            console.error(error);
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
    const officeLat = parseFloat(root.dataset.officeLat);
    const officeLng = parseFloat(root.dataset.officeLng);
    const radius = parseFloat(root.dataset.radius);
    const hasGeofence = Number.isFinite(officeLat) && Number.isFinite(officeLng) && radius > 0;

    const show = (html, tone) => {
        const tones = {
            success: 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-200',
            warning: 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200',
            error: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300',
        };
        result.className = `rounded-2xl border p-4 text-sm leading-relaxed ${tones[tone]}`;
        result.innerHTML = html;
    };

    button.addEventListener('click', () => {
        if (!navigator.geolocation?.getCurrentPosition || !window.isSecureContext) {
            show(locationErrorMessage(null), 'error');
            return;
        }

        button.disabled = true;
        button.textContent = 'Membaca lokasi...';

        navigator.geolocation.getCurrentPosition(
            ({ coords }) => {
                button.disabled = false;
                button.textContent = 'Cek Ulang Lokasi';

                const accuracy = `akurasi ±${Math.round(coords.accuracy)} meter`;

                if (!hasGeofence) {
                    show(`<strong>Lokasi terbaca</strong> (${accuracy}). Akses lokasi sudah siap untuk absensi.`, 'success');
                    return;
                }

                const distance = distanceMeters(coords.latitude, coords.longitude, officeLat, officeLng);

                if (distance <= radius) {
                    show(`<strong>Anda berada di area kantor.</strong> Jarak ${formatDistance(distance)} dari titik kantor (${accuracy}). Siap absen.`, 'success');
                } else {
                    show(`<strong>Akses lokasi berhasil</strong>, tetapi Anda berada ${formatDistance(distance)} dari kantor, di luar radius ${formatDistance(radius)} (${accuracy}). Absen hanya bisa dilakukan di area kantor.`, 'warning');
                }
            },
            (error) => {
                button.disabled = false;
                button.textContent = 'Coba Lagi';
                show(locationErrorMessage(error), 'error');
            },
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    });
}

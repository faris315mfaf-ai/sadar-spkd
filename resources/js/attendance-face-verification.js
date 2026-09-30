/**
 * Attendance Face Verification Modal — smile liveness + face match.
 */

import {
    getAttendanceReportHtml,
    getAttendanceReportPlainLength,
} from './attendance-report.js';
import { csrfFetch } from './csrf-fetch.js';
import { showToast } from './toast.js';
import { resolveAttendanceLocation } from './attendance-geolocation.js';
import {
    cameraErrorNotice,
    geolocationErrorNotice,
    serverErrorNotice,
    shouldShowDelayedVerificationNotice,
    VERIFICATION_NOTICES,
} from './attendance-verification-messages.js';
import {
    createDetectorOptions,
    detectFaces,
    ensureDetectionReady as loadDetectionModels,
    ensureRecognitionReady as loadRecognitionModel,
    isDetectionReady,
    preloadFaceModels,
    warmUpInference,
} from './face-api-runtime.js';
import { isTimeoutError, loadImage, sleep, withTimeout } from './async-timeout.js';
import {
    distanceToMatchPercent,
    formatMatchPercent,
    matchPercentBadgeClasses,
    meetsMinMatchPercent,
} from './face-match.js';
import {
    isFaceMismatchMessage,
    speak,
    speakWithMinDuration,
    stopSpeech,
    SPEECH_END_BUFFER_MS,
    VOICE_MESSAGES,
} from './speak.js';

const MIN_REPORT_LENGTH = 15;

// Time limits so no step can leave the modal spinning (iPhone Safari stalls silently).
const CAMERA_PERMISSION_TIMEOUT_MS = 60000;
const VIDEO_PLAY_TIMEOUT_MS = 8000;
const VIDEO_FRAMES_TIMEOUT_MS = 6000;
const LOCATION_TIMEOUT_MS = 45000;
const PROFILE_DESCRIPTOR_TIMEOUT_MS = 20000;
const SUBMIT_TIMEOUT_MS = 60000;
// Reload after success even if the spoken confirmation never finishes.
const SUCCESS_RELOAD_FALLBACK_MS = 5000;
// Larger frames only cost memory and upload time; detection works on 320 px.
const MAX_CAPTURE_SIZE = 720;

const FaceVerificationModal = {
    detectorOptions: null,
    stream: null,
    cameraPromise: null,
    runSeq: 0,
    submitting: false,
    currentForm: null,
    profileDescriptor: null,
    verified: false,
    confirmTimer: null,
    voiceCooldownMs: 1800,
    voiceEvents: new Map(),
    warmUpPromise: null,
    cachedLocation: null,
    keepCameraOnClose: true,
    capturedPhotoDataUrl: null,
    successAlertShown: false,
    verificationInFlight: false,
    verificationDelayTimer: null,
    locationInFlight: false,
    locationDelayTimer: null,
    activeNotice: null,
    reportListenerBound: false,

    el: (id) => document.getElementById(id),

    modal() { return this.el('face-verification-modal'); },
    videoEl() { return this.el('face-video'); },
    canvasEl() { return this.el('face-canvas'); },
    previewImgEl() { return this.el('face-preview-image'); },
    statusEl() { return this.el('face-status'); },
    badgeEl() { return this.el('face-status-badge'); },
    retryBtn() { return this.el('face-retry-button'); },
    cancelBtn() { return this.el('face-cancel-button'); },
    verifyBtn() { return this.el('face-verify-button'); },
    captureBtn() { return this.el('face-capture-button'); },
    retakeBtn() { return this.el('face-retake-button'); },
    usePhotoBtn() { return this.el('face-use-photo-button'); },
    profileImg() { return this.el('face-modal-profile-photo'); },
    titleEl() { return this.el('face-modal-title'); },
    challengeLabelEl() { return this.el('face-challenge-label'); },
    challengeHintEl() { return this.el('face-challenge-hint'); },
    matchPercentEl() { return this.el('face-match-percent'); },
    loadingPhaseEl() { return this.el('face-loading-phase'); },
    previewLabelEl() { return this.el('face-preview-label'); },
    liveIndicatorEl() { return this.el('face-live-indicator'); },
    cameraPanelEl() { return this.el('face-camera-panel'); },
    readyPanelEl() { return this.el('face-ready-panel'); },
    readyTitleEl() { return this.el('face-ready-title'); },
    readyHintEl() { return this.el('face-ready-hint'); },
    readyStepsHeadingEl() { return this.el('face-ready-steps-heading'); },
    readyStepsEl() { return this.el('face-ready-steps'); },
    loadingGuidanceEl() { return this.el('face-loading-guidance'); },
    panelLiveBadgeEl() { return this.el('face-panel-live-badge'); },

    config() {
        return window.attendanceFaceConfig || {};
    },

    threshold() {
        const fromForm = parseFloat(this.currentForm?.dataset.faceThreshold);
        if (!Number.isNaN(fromForm)) {
            return fromForm;
        }
        return parseFloat(this.config().threshold) || 0.5;
    },

    minMatchPercent() {
        const fromForm = parseInt(this.currentForm?.dataset.minMatchPercent, 10);
        if (!Number.isNaN(fromForm)) {
            return fromForm;
        }
        const fromConfig = parseInt(this.config().minMatchPercent, 10);
        return Number.isNaN(fromConfig) ? 74 : fromConfig;
    },

    async ensureDetectionReady() {
        if (isDetectionReady() && this.detectorOptions) {
            this.setPipelineStep('ai', true);
            return true;
        }

        const ok = await loadDetectionModels();
        if (ok) {
            this.detectorOptions = createDetectorOptions();
            this.setPipelineStep('ai', true);
        }
        return ok;
    },

    async ensureRecognitionReady() {
        return loadRecognitionModel();
    },

    setLoadingPhase(phase, message, badgeType = 'loading') {
        const labels = {
            gps: 'Mengambil lokasi GPS...',
            camera: 'Menyiapkan kamera & AI...',
            ai: 'Memuat model AI...',
            verify: 'Menyiapkan verifikasi...',
            ready: 'Kamera aktif — siap ambil foto',
            scanning: 'Memindai wajah...',
        };

        const phaseEl = this.loadingPhaseEl();
        if (phaseEl) {
            phaseEl.textContent = labels[phase] || message;
        }

        if (message) {
            this.setStatus(message, badgeType);
        } else if (labels[phase]) {
            this.setStatus(labels[phase], badgeType);
        }

        if (phase === 'ready') {
            this.revealCamera();
        } else if (['gps', 'camera', 'ai', 'verify'].includes(phase)) {
            this.revealLoadingStatus();
        }
    },

    isGpsReady() {
        if (!this.cachedLocation) {
            return false;
        }

        if (!this.currentForm) {
            return true;
        }

        return this.isLocationWithinGeofence(this.currentForm, this.cachedLocation);
    },

    isHardwareReady() {
        return this.isCameraActive() && this.isGpsReady();
    },

    setCaptureEnabled(enabled) {
        const btn = this.captureBtn();
        if (btn) {
            btn.disabled = !enabled;
        }
    },

    syncReadinessUi(statusType = 'loading') {
        const hardwareReady = this.isHardwareReady();
        const cameraLive = this.isCameraActive();
        const hasCapturedPhoto = Boolean(this.capturedPhotoDataUrl);
        const isError = statusType === 'error' || statusType === 'warning';
        const isWarning = statusType === 'warning';
        const isSuccess = statusType === 'success';

        const previewLabel = this.previewLabelEl();
        if (previewLabel) {
            if (hasCapturedPhoto) {
                previewLabel.textContent = 'Preview foto';
                previewLabel.classList.remove('hidden');
            } else if (cameraLive) {
                previewLabel.textContent = 'Preview mirror aktif';
                previewLabel.classList.remove('hidden');
            } else {
                previewLabel.textContent = 'Menyiapkan preview...';
                previewLabel.classList.remove('hidden');
            }
        }

        const liveIndicator = this.liveIndicatorEl();
        if (liveIndicator) {
            liveIndicator.classList.toggle('hidden', !hardwareReady || hasCapturedPhoto || isError);
        }

        const panelLive = this.panelLiveBadgeEl();
        if (panelLive) {
            panelLive.classList.toggle('hidden', !hardwareReady || isError);
        }

        const loadingGuidance = this.loadingGuidanceEl();
        if (loadingGuidance) {
            loadingGuidance.classList.toggle(
                'hidden',
                hardwareReady || hasCapturedPhoto || isError || isSuccess,
            );
        }

        const title = this.readyTitleEl();
        const hint = this.readyHintEl();
        const panel = this.readyPanelEl();

        if (title && hint) {
            if (isSuccess) {
                title.textContent = this.activeNotice?.title || 'Absensi berhasil';
                hint.textContent = this.activeNotice?.message || 'Data absensi telah berhasil tercatat.';
                title.className = 'mt-1 text-base font-bold text-emerald-800 dark:text-emerald-200 sm:text-lg';
                hint.className = 'mt-1 text-xs text-emerald-700/90 dark:text-emerald-300/90';
            } else if (isError) {
                title.textContent = this.activeNotice?.title || 'Verifikasi belum siap';
                hint.textContent = this.activeNotice?.message || 'Perbaiki masalah di bawah, lalu tekan Coba Lagi. Absensi belum tercatat.';
                title.className = isWarning
                    ? 'mt-1 text-base font-bold text-amber-800 dark:text-amber-200 sm:text-lg'
                    : 'mt-1 text-base font-bold text-red-800 dark:text-red-200 sm:text-lg';
                hint.className = isWarning
                    ? 'mt-1 text-xs text-amber-700/90 dark:text-amber-300/90'
                    : 'mt-1 text-xs text-red-700/90 dark:text-red-300/90';
            } else if (statusType === 'scanning' && this.activeNotice) {
                title.textContent = this.activeNotice.title;
                hint.textContent = this.activeNotice.message;
                title.className = 'mt-1 text-base font-bold text-blue-800 dark:text-blue-200 sm:text-lg';
                hint.className = 'mt-1 text-xs text-blue-700/90 dark:text-blue-300/90';
            } else if (hasCapturedPhoto) {
                title.textContent = 'Foto siap diperiksa';
                hint.textContent = 'Periksa hasil foto, lalu gunakan atau ulangi.';
                title.className = 'mt-1 text-base font-bold text-blue-800 dark:text-blue-200 sm:text-lg';
                hint.className = 'mt-1 text-xs text-blue-700/90 dark:text-blue-300/90';
            } else if (hardwareReady) {
                title.textContent = 'Verifikasi wajah realtime aktif';
                hint.textContent = 'Pastikan hanya satu wajah terlihat jelas di kamera.';
                title.className = 'mt-1 text-base font-bold text-blue-800 dark:text-blue-200 sm:text-lg';
                hint.className = 'mt-1 text-xs text-blue-700/90 dark:text-blue-300/90';
            } else if (!this.isGpsReady()) {
                title.textContent = 'Memakai izin lokasi dari halaman absensi';
                hint.textContent = 'Izin lokasi seharusnya sudah diizinkan saat halaman dibuka. Jika belum, muat ulang halaman lalu tekan Izinkan.';
                title.className = 'mt-1 text-base font-bold text-blue-800 dark:text-blue-200 sm:text-lg';
                hint.className = 'mt-1 text-xs text-blue-700/90 dark:text-blue-300/90';
            } else if (!cameraLive) {
                title.textContent = 'Memakai kamera yang sudah disiapkan';
                hint.textContent = 'Izin kamera seharusnya sudah diizinkan saat masuk halaman. Hadapkan wajah, lalu ambil foto. Jika kamera belum tampil, tekan Coba Lagi.';
                title.className = 'mt-1 text-base font-bold text-blue-800 dark:text-blue-200 sm:text-lg';
                hint.className = 'mt-1 text-xs text-blue-700/90 dark:text-blue-300/90';
            } else {
                title.textContent = 'Menyiapkan verifikasi...';
                hint.textContent = 'Tunggu lokasi GPS dan kamera siap sebelum mengambil foto.';
                title.className = 'mt-1 text-base font-bold text-blue-800 dark:text-blue-200 sm:text-lg';
                hint.className = 'mt-1 text-xs text-blue-700/90 dark:text-blue-300/90';
            }
        }

        if (panel) {
            panel.className = isSuccess
                ? 'rounded-xl border border-emerald-200 bg-emerald-50/90 px-4 py-3 dark:border-emerald-900/50 dark:bg-emerald-950/30'
                : isWarning
                ? 'rounded-xl border border-amber-200 bg-amber-50/90 px-4 py-3 dark:border-amber-900/50 dark:bg-amber-950/30'
                : isError
                    ? 'rounded-xl border border-red-200 bg-red-50/90 px-4 py-3 dark:border-red-900/50 dark:bg-red-950/30'
                    : 'rounded-xl border border-blue-100 bg-blue-50/90 px-4 py-3 dark:border-blue-900/50 dark:bg-blue-950/30';
        }

        this.setCaptureEnabled(hardwareReady && !hasCapturedPhoto && !isError);
    },

    async startCamera({ prewarm = false } = {}) {
        if (!navigator.mediaDevices?.getUserMedia) {
            if (!prewarm) {
                this.setNotice(VERIFICATION_NOTICES.cameraUnsupported);
            }
            return false;
        }

        if (this.isCameraActive()) {
            // Re-attach when the modal opens: iPhone Safari leaves a stream attached
            // while the modal was hidden frozen or black.
            const attached = await this.attachStreamToVideo({ force: !prewarm });
            if (!attached && !prewarm) {
                this.setNotice(VERIFICATION_NOTICES.cameraNotReady);
            }
            return attached;
        }

        // Page warm-up and the modal can ask at the same time; share one request.
        this.cameraPromise ??= this.openCameraStream().finally(() => {
            this.cameraPromise = null;
        });

        try {
            this.stream = await this.cameraPromise;
            this.watchCameraTracks(this.stream);
            const attached = await this.attachStreamToVideo({ force: true });
            if (!attached) {
                if (!prewarm) {
                    this.setNotice(VERIFICATION_NOTICES.cameraNotReady);
                }
                return false;
            }
            if (!prewarm) {
                this.setLoadingPhase('ready', 'Kamera aktif — siap ambil foto', 'ready');
            }
            this.setPipelineStep('camera', true);
            return true;
        } catch (error) {
            console.error('Gagal membuka kamera:', error);
            if (!prewarm) {
                this.setNotice(isTimeoutError(error) ? VERIFICATION_NOTICES.cameraNotReady : cameraErrorNotice(error));
            }
            return false;
        }
    },

    async openCameraStream() {
        const request = (constraints) => {
            const pending = navigator.mediaDevices.getUserMedia(constraints);

            // The permission prompt can be left unanswered; stop a stream that arrives too late.
            return withTimeout(pending, CAMERA_PERMISSION_TIMEOUT_MS, 'Membuka kamera').catch((error) => {
                if (isTimeoutError(error)) {
                    pending.then((late) => late.getTracks().forEach((track) => track.stop())).catch(() => { });
                }
                throw error;
            });
        };

        try {
            return await request({
                video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } },
                audio: false,
            });
        } catch (error) {
            if (!['OverconstrainedError', 'ConstraintNotSatisfiedError'].includes(error?.name)) {
                throw error;
            }
            return request({ video: true, audio: false });
        }
    },

    watchCameraTracks(stream) {
        stream?.getVideoTracks().forEach((track) => {
            // iOS ends the camera track when Safari goes to the background or a call comes in.
            track.addEventListener('ended', () => {
                if (this.stream === stream && this.isModalOpen() && !this.capturedPhotoDataUrl) {
                    this.setPipelineStep('camera', false);
                    this.setCaptureEnabled(false);
                    this.setNotice(VERIFICATION_NOTICES.cameraNotReady);
                }
            });
        });
    },

    isModalOpen() {
        return Boolean(this.modal() && !this.modal().classList.contains('hidden'));
    },

    isCameraActive() {
        return Boolean(this.stream?.active && this.stream.getVideoTracks().some((track) => track.readyState === 'live'));
    },

    hasVideoFrames() {
        const video = this.videoEl();
        return Boolean(video && video.videoWidth > 0 && video.videoHeight > 0 && video.readyState >= 2);
    },

    async waitForVideoFrames(timeoutMs = VIDEO_FRAMES_TIMEOUT_MS) {
        const deadline = Date.now() + timeoutMs;
        while (Date.now() < deadline) {
            if (this.hasVideoFrames()) {
                return true;
            }
            await sleep(100);
        }
        return this.hasVideoFrames();
    },

    async attachStreamToVideo({ force = false } = {}) {
        const video = this.videoEl();
        if (!video || !this.stream) {
            return false;
        }

        // iOS only plays camera video inline and muted.
        video.muted = true;
        video.playsInline = true;
        video.setAttribute('playsinline', '');
        video.setAttribute('muted', '');

        if (force || video.srcObject !== this.stream) {
            video.srcObject = null;
            video.srcObject = this.stream;
        }

        try {
            await withTimeout(video.play(), VIDEO_PLAY_TIMEOUT_MS, 'Memutar kamera');
        } catch (error) {
            console.warn('Preview kamera belum berjalan:', error);
        }

        // play() can resolve while the element still shows no picture; wait for real frames.
        if (this.modal()?.classList.contains('hidden')) {
            return !video.paused || this.hasVideoFrames();
        }

        return this.waitForVideoFrames();
    },

    stopCamera() {
        this.releaseCamera();
    },

    releaseCamera() {
        this.stream?.getTracks().forEach((t) => t.stop());
        this.stream = null;
        const video = this.videoEl();
        if (video) {
            video.srcObject = null;
        }
        this.setPipelineStep('camera', false);
    },

    async onVisibilityChange() {
        if (document.visibilityState !== 'visible' || !this.isModalOpen() || this.capturedPhotoDataUrl) {
            return;
        }

        // Coming back from another app: iOS may have paused the preview or ended the camera.
        const ok = this.isCameraActive()
            ? await this.resumePreview()
            : await this.startCamera();

        if (ok && this.currentForm && !this.verificationInFlight) {
            this.setPipelineStep('camera', true);
            this.setLoadingPhase('ready', 'Kamera aktif — siap ambil foto', 'ready');
        }
    },

    canWarmUp() {
        const cfg = this.config();
        return Boolean(cfg.profilePhotoUrl || cfg.hasFaceRegistered);
    },

    /**
     * Descriptor of the profile photo, used only for the match % shown before submitting.
     * The server makes the real decision, so failures here are ignored.
     */
    async preloadProfileDescriptor(photoUrl) {
        if (this.profileDescriptor || !photoUrl) {
            return Boolean(this.profileDescriptor);
        }

        const recognitionOk = await this.ensureRecognitionReady();
        if (!recognitionOk || !this.detectorOptions) {
            return false;
        }

        const img = await loadImage(photoUrl, { crossOrigin: 'anonymous' });
        const faces = await detectFaces(img, this.detectorOptions);

        if (faces.length === 1) {
            this.profileDescriptor = faces[0].descriptor;
        }

        return Boolean(this.profileDescriptor);
    },

    /**
     * Loads the models and compiles them in the background while the employee writes
     * the report. Nothing awaits this: open() and verification load what they need
     * themselves, each step with its own time limit.
     */
    warmUp() {
        if (!this.canWarmUp()) {
            return Promise.resolve(false);
        }

        if (this.warmUpPromise) {
            return this.warmUpPromise;
        }

        this.warmUpPromise = (async () => {
            preloadFaceModels();
            if (!(await this.ensureDetectionReady())) {
                return false;
            }
            await warmUpInference();

            const cfg = this.config();
            if (cfg.profilePhotoUrl && cfg.hasFaceRegistered && !cfg.needsFaceDescriptorSync) {
                await this.preloadProfileDescriptor(cfg.profilePhotoUrl).catch(() => false);
            }

            return true;
        })().catch(() => false).then((ok) => {
            if (!ok) {
                this.warmUpPromise = null;
            }
            return ok;
        });

        return this.warmUpPromise;
    },

    setNotice(notice) {
        if (['error', 'warning', 'success'].includes(notice.type)) {
            this.clearVerificationDelayNotice();
        }

        this.activeNotice = notice;
        this.setStatus(notice.message, notice.type, notice);
        this.renderNoticeSteps(notice.steps, notice.type);
        this.revealNotice(notice.type);

        const retry = this.retryBtn();
        if (retry) {
            retry.classList.toggle('hidden', notice.action !== 'retry');
            retry.querySelector('[data-button-label]')?.replaceChildren('Coba Lagi');
        }

        const retake = this.retakeBtn();
        if (retake) {
            retake.classList.toggle('hidden', notice.action !== 'retake');
        }

        if (notice.action === 'retake') {
            this.usePhotoBtn()?.classList.add('hidden');
        }
    },

    revealNotice(type) {
        if (type !== 'error' && type !== 'warning') {
            return;
        }

        this.scrollModalTo(this.readyPanelEl(), true);
    },

    revealLoadingStatus() {
        this.scrollModalTo(this.readyPanelEl());
    },

    revealCamera() {
        this.scrollModalTo(this.cameraPanelEl());
    },

    scrollModalTo(element, focus = false) {
        window.requestAnimationFrame(() => {
            if (!element) {
                return;
            }

            element.scrollIntoView({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                block: 'center',
            });
            if (focus) {
                element.focus({ preventScroll: true });
            }
        });
    },

    renderNoticeSteps(steps = [], type = 'error') {
        const heading = this.readyStepsHeadingEl();
        const list = this.readyStepsEl();
        if (!list) {
            return;
        }

        list.replaceChildren();
        heading?.classList.toggle('hidden', steps.length === 0);
        list.classList.toggle('hidden', steps.length === 0);

        const warning = type === 'warning';
        if (heading) {
            heading.className = steps.length === 0
                ? 'hidden'
                : `mt-3 border-t pt-3 text-xs font-bold ${
                    warning
                        ? 'border-amber-200/70 text-amber-800 dark:border-amber-800/60 dark:text-amber-200'
                        : 'border-red-200/70 text-red-800 dark:border-red-800/60 dark:text-red-200'
                }`;
        }
        list.className = steps.length === 0
            ? 'hidden'
            : `mt-2 list-decimal space-y-2 pl-5 text-xs leading-relaxed ${
                warning
                    ? 'text-amber-800 dark:text-amber-200'
                    : 'text-red-800 dark:text-red-200'
            }`;

        steps.forEach((step) => {
            const item = document.createElement('li');
            item.textContent = step;
            list.appendChild(item);
        });
    },

    setStatus(message, type = 'loading', notice = null) {
        const status = this.statusEl();
        const badge = this.badgeEl();

        if (notice) {
            this.activeNotice = notice;
        } else if (type !== 'error' && type !== 'warning') {
            this.activeNotice = null;
            this.renderNoticeSteps();
        }

        if (status) {
            status.textContent = notice?.title || message;
            status.setAttribute('aria-live', 'polite');
        }
        if (!badge) {
            this.syncReadinessUi(type);
            return;
        }

        const map = {
            loading: 'inline-flex rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-600 dark:bg-gray-700 dark:text-gray-300',
            ready: 'inline-flex rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-800 dark:bg-green-900/50 dark:text-green-200',
            error: 'inline-flex rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-800 dark:bg-red-900/50 dark:text-red-200',
            warning: 'inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-900/50 dark:text-amber-200',
            scanning: 'inline-flex rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-800 dark:bg-blue-900/50 dark:text-blue-200',
            success: 'inline-flex rounded-full bg-green-100 px-3 py-1 text-xs font-semibold text-green-800 dark:bg-green-900/50 dark:text-green-200',
        };
        const label = {
            loading: 'Memuat...',
            ready: 'Siap',
            error: 'Gagal',
            warning: 'Perhatian',
            scanning: 'Memindai...',
            success: 'Berhasil',
        };
        badge.className = map[type] || map.loading;
        badge.textContent = label[type] || 'Memuat...';
        this.syncReadinessUi(type);
    },

    setPipelineStep(step, active) {
        const el = this.el(`face-pipeline-${step}`);
        if (!el) {
            return;
        }

        const dot = el.querySelector('[data-pipeline-dot]');
        const base = 'flex items-center gap-2 rounded-lg border px-2.5 py-2 text-[11px] font-medium transition-colors';
        if (active) {
            el.className = `${base} border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800/50 dark:bg-emerald-950/40 dark:text-emerald-200`;
            if (dot) {
                dot.className = 'h-2 w-2 shrink-0 rounded-full bg-emerald-500';
            }
            return;
        }

        el.className = `${base} border-gray-200 bg-gray-50 text-gray-500 dark:border-gray-600 dark:bg-gray-900/40 dark:text-gray-400`;
        if (dot) {
            dot.className = 'h-2 w-2 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600';
        }
    },

    resetPipeline() {
        ['camera', 'ai', 'face', 'verify', 'match'].forEach((step) => {
            this.setPipelineStep(step, false);
        });
        const matchEl = this.matchPercentEl();
        if (matchEl) {
            matchEl.classList.add('hidden');
            matchEl.textContent = '—';
            matchEl.className = 'hidden inline-flex rounded-full px-2.5 py-1 text-[11px] font-semibold';
        }
    },

    showMatchPercent(percent) {
        const el = this.matchPercentEl();
        if (!el || percent == null) {
            return;
        }
        el.textContent = formatMatchPercent(percent);
        el.className = matchPercentBadgeClasses(percent);
        el.classList.remove('hidden');
        this.setPipelineStep('match', true);
    },

    clearConfirmTimer() {
        if (this.confirmTimer) {
            clearTimeout(this.confirmTimer);
            this.confirmTimer = null;
        }
    },

    startVerificationDelayNotice() {
        this.clearVerificationDelayNotice();
        this.verificationDelayTimer = window.setTimeout(() => {
            if (shouldShowDelayedVerificationNotice({
                verificationInFlight: this.verificationInFlight,
                verified: this.verified,
                activeNoticeType: this.activeNotice?.type,
            })) {
                this.setNotice(VERIFICATION_NOTICES.verificationTakingLonger);
            }
        }, 4000);
    },

    clearVerificationDelayNotice() {
        if (this.verificationDelayTimer) {
            window.clearTimeout(this.verificationDelayTimer);
            this.verificationDelayTimer = null;
        }
    },

    startLocationDelayNotice() {
        this.clearLocationDelayNotice();
        this.locationDelayTimer = window.setTimeout(() => {
            if (this.locationInFlight && !this.isGpsReady()) {
                this.setNotice(VERIFICATION_NOTICES.gpsTakingLonger);
            }
        }, 4000);
    },

    clearLocationDelayNotice() {
        if (this.locationDelayTimer) {
            window.clearTimeout(this.locationDelayTimer);
            this.locationDelayTimer = null;
        }
    },

    /**
     * Speaks without waiting. iPhone Safari often never finishes an utterance that was
     * not started by a tap; awaiting it used to freeze verification on "Memverifikasi...".
     */
    playVoice(text) {
        speak(text).catch(() => { });
    },

    playSuccessSound() {
        try {
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            if (!AudioContext) {
                return;
            }

            const context = new AudioContext();
            const playTone = (frequency, startTime, duration) => {
                const oscillator = context.createOscillator();
                const gain = context.createGain();

                oscillator.type = 'sine';
                oscillator.frequency.value = frequency;
                gain.gain.setValueAtTime(0.0001, startTime);
                gain.gain.exponentialRampToValueAtTime(0.12, startTime + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, startTime + duration);

                oscillator.connect(gain);
                gain.connect(context.destination);
                oscillator.start(startTime);
                oscillator.stop(startTime + duration);
            };

            const now = context.currentTime;
            playTone(880, now, 0.12);
            playTone(1175, now + 0.12, 0.18);

            window.setTimeout(() => {
                context.close().catch(() => {});
            }, 500);
        } catch {
            // ignore audio errors
        }
    },

    showSuccessAlert() {
        if (this.successAlertShown) {
            return;
        }
        this.successAlertShown = true;

        const modal = this.modal();
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = '';
        }

        showToast('success', 'Absensi berhasil. Data telah tercatat.', 2500);

        this.playSuccessSound();
    },

    announce(key, text, cooldownMs = this.voiceCooldownMs) {
        if (!text) {
            return;
        }
        const now = Date.now();
        const last = this.voiceEvents.get(key) || 0;
        if (now - last < cooldownMs) {
            return;
        }
        this.voiceEvents.set(key, now);
        this.playVoice(text);
    },

    scheduleConfirmAfterSuccess() {
        this.clearConfirmTimer();
        const reload = () => {
            this.confirmTimer = null;
            if (this.verified) {
                this.confirm();
            }
        };

        // The attendance is saved; reload even if the spoken confirmation never ends.
        this.confirmTimer = setTimeout(reload, SUCCESS_RELOAD_FALLBACK_MS);
        speakWithMinDuration(VOICE_MESSAGES.VERIFY_SUCCESS).then(() => {
            if (!this.confirmTimer) {
                return;
            }
            this.clearConfirmTimer();
            this.confirmTimer = setTimeout(reload, SPEECH_END_BUFFER_MS);
        });
    },

    /**
     * Draws the current frame un-mirrored, like the registered face photo. The live
     * preview is mirrored for comfort, but a mirrored face scores clearly lower
     * against the registered one (about 0.28 extra distance in testing).
     */
    captureFrame() {
        const video = this.videoEl();
        const canvas = this.canvasEl();
        const scale = Math.min(1, MAX_CAPTURE_SIZE / Math.max(video.videoWidth, video.videoHeight));
        canvas.width = Math.round(video.videoWidth * scale);
        canvas.height = Math.round(video.videoHeight * scale);

        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

        return canvas.toDataURL('image/jpeg', 0.85);
    },

    capturePhoto() {
        if (!this.isHardwareReady()) {
            this.setNotice(
                this.isGpsReady()
                    ? VERIFICATION_NOTICES.cameraNotReady
                    : VERIFICATION_NOTICES.gpsUnavailable,
            );
            return;
        }

        const video = this.videoEl();
        const previewImg = this.previewImgEl();
        const canvas = this.canvasEl();

        if (!video || !previewImg || !canvas) {
            return;
        }

        // Without decoded frames the photo would be empty (black on iPhone): re-attach instead.
        if (!this.hasVideoFrames()) {
            this.setCaptureEnabled(false);
            this.setNotice(VERIFICATION_NOTICES.cameraNotReady);
            this.attachStreamToVideo({ force: true }).then((ok) => {
                if (ok && this.isModalOpen() && !this.capturedPhotoDataUrl) {
                    this.setLoadingPhase('ready', 'Kamera aktif — siap ambil foto', 'ready');
                }
            });
            return;
        }

        this.capturedPhotoDataUrl = this.captureFrame();
        previewImg.src = this.capturedPhotoDataUrl;

        // Show preview, hide video
        video.classList.add('hidden');
        previewImg.classList.remove('hidden');

        // Update buttons
        this.captureBtn()?.classList.add('hidden');
        this.retakeBtn()?.classList.remove('hidden');
        this.usePhotoBtn()?.classList.remove('hidden');
        this.usePhotoBtn() && (this.usePhotoBtn().disabled = !this.isGpsReady());

        this.setStatus('Foto berhasil diambil. Silakan periksa hasilnya.', 'ready');
    },

    retakePhoto() {
        const video = this.videoEl();
        const previewImg = this.previewImgEl();

        if (!video || !previewImg) {
            return;
        }

        // Clear captured photo
        this.capturedPhotoDataUrl = null;
        previewImg.src = '';

        // Show video, hide preview
        video.classList.remove('hidden');
        previewImg.classList.add('hidden');

        // Update buttons
        this.captureBtn()?.classList.remove('hidden');
        this.retakeBtn()?.classList.add('hidden');
        this.usePhotoBtn()?.classList.add('hidden');

        if (this.isHardwareReady()) {
            this.setStatus('Kamera aktif — siap ambil foto', 'ready');
            this.resumePreview().catch(() => { });
        } else {
            this.setStatus('Menyiapkan kamera dan lokasi GPS...', 'loading');
            this.setCaptureEnabled(false);
        }
    },

    /**
     * Safari pauses a hidden camera preview and may not restart it when shown again.
     */
    async resumePreview() {
        const video = this.videoEl();
        if (!video || !this.isCameraActive()) {
            return false;
        }

        try {
            await withTimeout(video.play(), VIDEO_PLAY_TIMEOUT_MS, 'Memutar kamera');
        } catch {
            // Falls through to a full re-attach below.
        }

        if (!video.paused && await this.waitForVideoFrames(1500)) {
            return true;
        }

        return this.attachStreamToVideo({ force: true });
    },

    distance(a, b) {
        return Math.hypot(a.x - b.x, a.y - b.y);
    },

    averagePoint(points) {
        const total = points.reduce((acc, point) => ({
            x: acc.x + point.x,
            y: acc.y + point.y,
        }), { x: 0, y: 0 });
        return { x: total.x / points.length, y: total.y / points.length };
    },

    attendanceForm() {
        return document.querySelector('[data-attendance-face-form]');
    },

    async fetchLocation(form) {
        try {
            return await withTimeout(resolveAttendanceLocation({
                mapId: form.dataset.geofenceMapId || '',
                requiresGeofence: form.dataset.requiresGeofence === '1',
            }), LOCATION_TIMEOUT_MS, 'Mengambil lokasi');
        } catch (error) {
            if (isTimeoutError(error)) {
                error.verificationNotice = geolocationErrorNotice({ code: 3 });
            }
            throw error;
        }
    },

    async preloadPageLocation() {
        const form = this.attendanceForm();

        if (!form) {
            return;
        }

        try {
            this.cachedLocation = await this.fetchLocation(form);
        } catch {
            this.cachedLocation = null;
        }
    },

    isLocationWithinGeofence(form, geo) {
        return form.dataset.requiresGeofence !== '1' || geo.withinRadius;
    },

    async acquireLocation(form) {
        this.locationInFlight = true;
        this.setLoadingPhase('gps', 'Mengambil lokasi GPS...', 'scanning');
        this.setNotice(VERIFICATION_NOTICES.gpsAcquiring);
        this.startLocationDelayNotice();
        this.revealLoadingStatus();

        try {
            const geo = await this.fetchLocation(form);

            if (!this.isLocationWithinGeofence(form, geo)) {
                this.cachedLocation = null;
                this.setNotice(VERIFICATION_NOTICES.gpsOutsideRadius);
                this.announce('gps-invalid', VOICE_MESSAGES.GPS_INVALID);
                return false;
            }

            this.cachedLocation = geo;
            return true;
        } catch (err) {
            console.error('Gagal mengambil lokasi absensi:', err);
            this.cachedLocation = null;
            this.setNotice(err.verificationNotice || VERIFICATION_NOTICES.gpsUnavailable);
            return false;
        } finally {
            this.locationInFlight = false;
            this.clearLocationDelayNotice();
        }
    },

    async ensureLocationReady(form) {
        if (this.cachedLocation) {
            if (!this.isLocationWithinGeofence(form, this.cachedLocation)) {
                this.cachedLocation = null;
                this.setNotice(VERIFICATION_NOTICES.gpsOutsideRadius);
                this.announce('gps-invalid', VOICE_MESSAGES.GPS_INVALID);
                return false;
            }

            return true;
        }

        return this.acquireLocation(form);
    },

    async syncProfileDescriptor(form) {
        if (form.dataset.needsSync !== '1' || !form.dataset.profilePhotoUrl) {
            return true;
        }

        this.setStatus('Menyiapkan data wajah dari foto profil...', 'loading');

        let detections;
        try {
            const img = await loadImage(form.dataset.profilePhotoUrl, { crossOrigin: 'anonymous' });
            detections = await detectFaces(img, this.detectorOptions);
        } catch (error) {
            console.error('Foto profil gagal diproses:', error);
            this.setNotice(VERIFICATION_NOTICES.faceCheckFailed);
            return false;
        }

        if (detections.length !== 1) {
            this.setNotice({
                ...VERIFICATION_NOTICES.profileMissing,
                title: 'Foto profil belum dapat diverifikasi',
                message: detections.length < 1
                    ? 'Wajah tidak terdeteksi pada foto profil. Hubungi HR untuk memperbarui foto profil. Absensi belum tercatat.'
                    : 'Foto profil menampilkan lebih dari satu wajah. Hubungi HR untuk memperbarui foto profil. Absensi belum tercatat.',
            });
            return false;
        }

        this.profileDescriptor = detections[0].descriptor;

        let response;
        try {
            response = await this.postJson(form.dataset.syncUrl, {
                face_descriptor: Array.from(detections[0].descriptor),
                faces_detected: 1,
            });
        } catch (error) {
            console.error('Sinkronisasi data wajah gagal:', error);
            this.setNotice(error?.name === 'AbortError' ? VERIFICATION_NOTICES.submitTimeout : VERIFICATION_NOTICES.networkFailure);
            return false;
        }

        if (!response) {
            return false;
        }

        const { res, data } = response;
        if (!res.ok || !data.success) {
            this.setNotice(serverErrorNotice(data.message || 'Data wajah dari foto profil gagal disiapkan.'));
            return false;
        }

        form.dataset.needsSync = '0';
        return true;
    },

    /**
     * Best effort: the match % is only a hint, so give up quietly after a short wait.
     */
    async ensureProfileDescriptor(form) {
        if (this.profileDescriptor) {
            return true;
        }
        if (!form.dataset.profilePhotoUrl) {
            return false;
        }

        try {
            return await withTimeout(
                this.preloadProfileDescriptor(form.dataset.profilePhotoUrl),
                PROFILE_DESCRIPTOR_TIMEOUT_MS,
                'Foto profil',
            );
        } catch (error) {
            console.warn('Foto profil tidak dapat dibandingkan di perangkat:', error);
            return false;
        }
    },

    /**
     * POSTs JSON with a time limit. Resolves null when the CSRF token expired
     * (the page reloads itself); throws AbortError on timeout.
     */
    async postJson(url, payload, timeoutMs = SUBMIT_TIMEOUT_MS) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), timeoutMs);

        try {
            const res = await csrfFetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(payload),
                signal: controller.signal,
            });

            if (!res) {
                return null;
            }

            // A proxy error page is HTML, not JSON.
            const data = await res.json().catch(() => ({}));
            return { res, data };
        } finally {
            clearTimeout(timer);
        }
    },

    async runVerification(form) {
        // Closing the modal bumps runSeq; a stale run stops at its next step.
        const run = ++this.runSeq;
        const cancelled = () => run !== this.runSeq || this.currentForm !== form;

        this.retryBtn()?.classList.add('hidden');
        this.verified = false;
        this.setPipelineStep('verify', false);
        this.setPipelineStep('match', false);
        this.setNotice(VERIFICATION_NOTICES.verificationProcessing);

        const canvas = this.canvasEl();
        if (!this.capturedPhotoDataUrl || !canvas?.width || !canvas?.height) {
            this.setNotice(VERIFICATION_NOTICES.photoMissing);
            this.retakePhoto();
            return;
        }

        const detectionOk = await this.ensureDetectionReady();
        if (cancelled()) {
            return;
        }
        if (!detectionOk) {
            this.setNotice(VERIFICATION_NOTICES.aiUnavailable);
            return;
        }

        this.setLoadingPhase('verify', 'Memverifikasi wajah...', 'scanning');
        this.setNotice(VERIFICATION_NOTICES.verificationProcessing);
        this.setPipelineStep('verify', true);

        const recognitionOk = await this.ensureRecognitionReady();
        if (cancelled()) {
            return;
        }
        if (!recognitionOk) {
            this.setNotice(VERIFICATION_NOTICES.aiUnavailable);
            return;
        }

        if (form.dataset.needsSync === '1') {
            const synced = await this.syncProfileDescriptor(form);
            if (cancelled() || !synced) {
                return;
            }
        }

        // The canvas still holds the captured frame; no JPEG round trip needed.
        let faces;
        try {
            faces = await detectFaces(canvas, this.detectorOptions);
        } catch (error) {
            console.error('Pemeriksaan wajah gagal:', error);
            if (!cancelled()) {
                this.setNotice(VERIFICATION_NOTICES.faceCheckFailed);
            }
            return;
        }
        if (cancelled()) {
            return;
        }

        if (faces.length === 0 || !faces[0]?.descriptor) {
            this.setNotice(VERIFICATION_NOTICES.faceNotDetected);
            this.announce('face-not-detected-verify', VOICE_MESSAGES.FACE_NOT_DETECTED);
            return;
        }
        if (faces.length > 1) {
            this.setNotice(VERIFICATION_NOTICES.multipleFaces);
            this.announce('multiple-face-verify', VOICE_MESSAGES.MULTIPLE_FACE);
            return;
        }

        const liveDescriptor = faces[0].descriptor;

        // Early hint only; the server compares against the registered face and decides.
        await this.ensureProfileDescriptor(form);
        if (cancelled()) {
            return;
        }

        if (this.profileDescriptor) {
            const distance = faceapi.euclideanDistance(liveDescriptor, this.profileDescriptor);
            const percent = distanceToMatchPercent(distance);
            const minPercent = this.minMatchPercent();
            this.showMatchPercent(percent);

            if (!meetsMinMatchPercent(percent, minPercent)) {
                this.setNotice({
                    ...VERIFICATION_NOTICES.faceMismatch,
                    message: `Kecocokan wajah hanya ${percent ?? 0}%, sedangkan batas minimal adalah ${minPercent}%. Ikuti langkah perbaikan di bawah lalu ambil foto ulang. Absensi belum tercatat.`,
                });
                this.playVoice(VOICE_MESSAGES.FACE_MATCH_TOO_LOW);
                return;
            }
        }

        const photo = this.capturedPhotoDataUrl;
        const reportName = form.dataset.type === 'clock-in' ? 'clock_in_report' : 'clock_out_report';
        const report = getAttendanceReportHtml(form);

        const geo = this.cachedLocation;

        if (!geo) {
            this.setNotice(VERIFICATION_NOTICES.gpsUnavailable);
            return;
        }

        if (!this.isLocationWithinGeofence(form, geo)) {
            this.cachedLocation = null;
            this.setNotice(VERIFICATION_NOTICES.gpsOutsideRadius);
            this.announce('gps-invalid', VOICE_MESSAGES.GPS_INVALID);
            return;
        }

        const payload = {
            [reportName]: report,
            face_descriptor: Array.from(liveDescriptor),
            faces_detected: 1,
            verification_photo: photo,
            latitude: geo.latitude,
            longitude: geo.longitude,
        };

        if (geo.location) {
            payload.attendance_location = geo.location;
        }

        let response;
        this.submitting = true;
        try {
            response = await this.postJson(form.dataset.action, payload);
        } catch (error) {
            console.error('Gagal mengirim absensi:', error);
            this.setNotice(error?.name === 'AbortError' ? VERIFICATION_NOTICES.submitTimeout : VERIFICATION_NOTICES.networkFailure);
            return;
        } finally {
            this.submitting = false;
        }

        if (!response) {
            return;
        }

        const { res, data } = response;

        if (!res.ok || !data.success) {
            if (isFaceMismatchMessage(data.message)) {
                this.setNotice({
                    ...VERIFICATION_NOTICES.faceMismatch,
                    message: serverErrorNotice(data.message).message,
                });
                this.playVoice(VOICE_MESSAGES.FACE_NOT_MATCH);
            } else {
                this.setNotice(serverErrorNotice(
                    data.message || (res.status >= 500
                        ? `Server sedang mengalami gangguan (kode ${res.status})`
                        : `Permintaan ditolak server (kode ${res.status})`),
                ));
            }
            return;
        }

        this.verified = true;
        this.usePhotoBtn()?.classList.add('hidden');
        this.markFormAttendanceCompleted(form);
        this.setNotice(VERIFICATION_NOTICES.success);
        this.showSuccessAlert();
        this.scheduleConfirmAfterSuccess();
    },

    /**
     * Persist success on the form immediately so a GPS failure on a second open
     * (before page reload) cannot claim attendance was never recorded.
     */
    markFormAttendanceCompleted(form) {
        if (!form) {
            return;
        }

        const actionType = form.dataset.type;
        if (actionType === 'clock-in') {
            form.dataset.hasClockIn = '1';
        }
        if (actionType === 'clock-out') {
            form.dataset.hasClockOut = '1';
        }

        form.querySelectorAll(`[data-attendance-action="${actionType}"]`).forEach((button) => {
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');
        });
    },

    async open(form) {
        const cfg = this.config();

        if (!cfg.hasFaceRegistered && !cfg.profilePhotoUrl) {
            this.showFormAlert(
                form,
                VERIFICATION_NOTICES.profileMissing.title,
                VERIFICATION_NOTICES.profileMissing.message,
                'error',
            );
            return;
        }

        if (form.dataset.type === 'clock-in' && form.dataset.hasClockIn === '1') {
            showToast('warning', 'Anda sudah absen masuk', 2000);
            return;
        }

        if (form.dataset.type === 'clock-out' && form.dataset.hasClockOut === '1') {
            showToast('warning', 'Anda sudah absen pulang', 2000);
            return;
        }

        this.currentForm = form;
        this.verified = false;
        this.verificationInFlight = false;
        this.clearVerificationDelayNotice();
        this.clearLocationDelayNotice();
        this.locationInFlight = false;
        this.successAlertShown = false;
        this.voiceEvents.clear();
        stopSpeech();
        this.clearConfirmTimer();
        this.resetPipeline();

        // Reset preview state
        this.capturedPhotoDataUrl = null;
        const previewImg = this.previewImgEl();
        if (previewImg) {
            previewImg.src = '';
            previewImg.classList.add('hidden');
        }
        const video = this.videoEl();
        if (video) {
            video.classList.remove('hidden');
        }

        // Reset buttons
        this.captureBtn()?.classList.remove('hidden');
        this.setCaptureEnabled(false);
        this.retakeBtn()?.classList.add('hidden');
        this.usePhotoBtn()?.classList.add('hidden');

        const label = form.dataset.type === 'clock-in' ? 'Absen Masuk' : 'Absen Pulang';
        if (this.titleEl()) {
            this.titleEl().textContent = `Verifikasi Wajah — ${label}`;
        }

        const photoUrl = form.dataset.profilePhotoUrl || cfg.profilePhotoUrl || '';
        const profileImg = this.profileImg();
        if (profileImg && photoUrl) {
            profileImg.src = photoUrl;
            profileImg.classList.remove('hidden');
        }

        const modal = this.modal();
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.style.overflow = 'hidden';

        this.retryBtn()?.classList.add('hidden');
        this.setLoadingPhase('gps', 'Mengambil lokasi GPS...', 'scanning');

        try {
            const locationOk = await this.ensureLocationReady(form);
            if (!locationOk) {
                this.setCaptureEnabled(false);
                return;
            }

            this.setLoadingPhase(
                'camera',
                'Kamera dan model AI sedang dimuat. Mohon tunggu...',
                'loading',
            );

            // Keep compiling the models in the background; the photo can be taken meanwhile.
            this.warmUp();
            const aiPromise = this.ensureDetectionReady();

            const camOk = await this.startCamera();
            if (!camOk || this.currentForm !== form) {
                this.setCaptureEnabled(false);
                return;
            }
            this.setPipelineStep('camera', true);

            if (!isDetectionReady()) {
                this.setLoadingPhase('ai', 'Model AI sedang dimuat. Mohon tunggu...', 'loading');
            }

            const aiOk = await aiPromise;
            // The employee may have closed the modal or already taken the photo meanwhile.
            if (this.currentForm !== form || this.capturedPhotoDataUrl) {
                return;
            }
            if (!aiOk) {
                this.setNotice(VERIFICATION_NOTICES.aiUnavailable);
                this.setCaptureEnabled(false);
                return;
            }

            this.setLoadingPhase('ready', 'Kamera aktif — siap ambil foto', 'ready');
        } catch (err) {
            console.error('Gagal menyiapkan verifikasi absensi:', err);
            this.setNotice(err.verificationNotice || VERIFICATION_NOTICES.networkFailure);
            this.setCaptureEnabled(false);
        }
    },

    close() {
        // Only the upload itself must not be abandoned; face checks can be cancelled.
        if (this.submitting) {
            this.setNotice(VERIFICATION_NOTICES.verificationTakingLonger);
            return;
        }

        this.runSeq += 1;
        stopSpeech();
        this.clearConfirmTimer();
        if (!this.keepCameraOnClose) {
            this.releaseCamera();
        }
        this.verified = false;
        this.verificationInFlight = false;
        this.locationInFlight = false;
        this.clearVerificationDelayNotice();
        this.clearLocationDelayNotice();
        if (!this.keepCameraOnClose) {
            this.profileDescriptor = null;
        }
        this.currentForm = null;
        this.resetPipeline();

        // Reset preview state
        this.capturedPhotoDataUrl = null;
        const previewImg = this.previewImgEl();
        if (previewImg) {
            previewImg.src = '';
            previewImg.classList.add('hidden');
        }
        const video = this.videoEl();
        if (video) {
            video.classList.remove('hidden');
        }

        // Reset buttons
        this.captureBtn()?.classList.remove('hidden');
        this.setCaptureEnabled(false);
        this.retakeBtn()?.classList.add('hidden');
        this.usePhotoBtn()?.classList.add('hidden');

        if (this.challengeLabelEl()) {
            this.challengeLabelEl().textContent = 'Verifikasi Wajah';
        }
        if (this.challengeHintEl()) {
            this.challengeHintEl().textContent = 'Hadapkan wajah ke kamera.';
        }
        const modal = this.modal();
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = '';
        this.syncReadinessUi('loading');
    },

    confirm() {
        if (!this.verified || !this.currentForm) {
            return;
        }
        this.clearConfirmTimer();
        stopSpeech();
        this.close();
        window.location.reload();
    },

    setFormAction(form, actionType) {
        form.dataset.type = actionType;
        form.dataset.action = actionType === 'clock-in'
            ? form.dataset.actionClockIn
            : form.dataset.actionClockOut;
    },

    beginAttendanceAction(form, actionType) {
        if (form.dataset.dualAction !== '1') {
            return;
        }

        if (actionType === 'clock-in' && form.dataset.hasClockIn === '1') {
            showToast('warning', 'Anda sudah absen masuk', 2000);
            return;
        }

        if (actionType === 'clock-out' && form.dataset.hasClockOut === '1') {
            showToast('warning', 'Anda sudah absen pulang', 2000);
            return;
        }

        this.setFormAction(form, actionType);

        const reportPlainLength = getAttendanceReportPlainLength(form);

        if (reportPlainLength < MIN_REPORT_LENGTH) {
            this.showFormAlert(
                form,
                VERIFICATION_NOTICES.reportIncomplete.title,
                VERIFICATION_NOTICES.reportIncomplete.message,
                'error',
                'report-incomplete',
            );
            return;
        }

        this.open(form);
    },

    showFormAlert(form, title, message, type, code = '') {
        const alert = form.querySelector('[id$="-alert"]');
        if (!alert) {
            return;
        }

        alert.classList.remove('hidden');
        alert.setAttribute('role', 'alert');
        alert.dataset.alertCode = code;
        alert.replaceChildren();

        const titleElement = document.createElement('p');
        titleElement.className = 'font-semibold';
        titleElement.textContent = title;

        const messageElement = document.createElement('p');
        messageElement.className = 'mt-1 text-xs font-normal leading-relaxed';
        messageElement.textContent = message;

        alert.append(titleElement, messageElement);
        alert.className = type === 'error'
            ? 'rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200'
            : 'rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800 dark:bg-green-950/40 dark:text-green-200';
    },

    clearFormAlert(form, code = '') {
        const alert = form?.querySelector('[id$="-alert"]');
        if (!alert || (code && alert.dataset.alertCode !== code)) {
            return;
        }

        alert.classList.add('hidden');
        alert.removeAttribute('role');
        alert.removeAttribute('data-alert-code');
        alert.replaceChildren();
    },

    boot() {
        const modal = this.modal();
        if (!modal) {
            return;
        }

        this.cancelBtn()?.addEventListener('click', () => this.close());

        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                this.close();
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !modal.classList.contains('hidden')) {
                this.close();
            }
        });

        if (!this.reportListenerBound) {
            this.reportListenerBound = true;

            document.addEventListener('attendance-report-change', (event) => {
                const form = event.target.closest?.('[data-attendance-face-form]');
                if (form && (event.detail?.plainLength ?? 0) >= MIN_REPORT_LENGTH) {
                    this.clearFormAlert(form, 'report-incomplete');
                }
            });

            document.addEventListener('input', (event) => {
                const form = event.target.closest?.('[data-attendance-face-form]');
                if (form && getAttendanceReportPlainLength(form) >= MIN_REPORT_LENGTH) {
                    this.clearFormAlert(form, 'report-incomplete');
                }
            });
        }

        this.captureBtn()?.addEventListener('click', () => {
            this.capturePhoto();
        });

        this.retakeBtn()?.addEventListener('click', () => {
            this.retakePhoto();
        });

        this.usePhotoBtn()?.addEventListener('click', async () => {
            if (!this.currentForm || this.verificationInFlight || this.verified) return;

            if (!this.isGpsReady()) {
                this.setNotice(VERIFICATION_NOTICES.gpsUnavailable);
                return;
            }

            if (!this.capturedPhotoDataUrl) {
                this.setNotice(VERIFICATION_NOTICES.photoMissing);
                return;
            }

            const btn = this.usePhotoBtn();
            if (btn) {
                const form = this.currentForm;
                this.verificationInFlight = true;
                this.startVerificationDelayNotice();
                btn.disabled = true;
                // Retaking now would redraw the canvas that is being checked.
                const retake = this.retakeBtn();
                if (retake) {
                    retake.disabled = true;
                }
                const originalText = btn.textContent;
                btn.textContent = 'Memverifikasi...';

                try {
                    await this.runVerification(form);
                } catch (error) {
                    console.error('Gagal mengirim verifikasi absensi:', error);
                    if (this.currentForm === form) {
                        this.setNotice(VERIFICATION_NOTICES.networkFailure);
                    }
                } finally {
                    this.clearVerificationDelayNotice();
                    this.verificationInFlight = false;
                    if (retake) {
                        retake.disabled = false;
                    }
                    if (!this.verified) {
                        btn.disabled = !this.isGpsReady();
                        btn.textContent = originalText;
                    }
                }
            }
        });

        this.retryBtn()?.addEventListener('click', async () => {
            if (!this.currentForm) {
                return;
            }
            stopSpeech();
            this.clearConfirmTimer();
            this.retryBtn().classList.add('hidden');
            this.setCaptureEnabled(false);

            if (!this.cachedLocation) {
                this.setLoadingPhase('gps', 'Mengambil lokasi GPS...', 'scanning');
                const locationOk = await this.ensureLocationReady(this.currentForm);
                if (!locationOk) {
                    this.setCaptureEnabled(false);
                    return;
                }
            }

            // Reset preview state
            this.capturedPhotoDataUrl = null;
            const previewImg = this.previewImgEl();
            if (previewImg) {
                previewImg.src = '';
                previewImg.classList.add('hidden');
            }
            const video = this.videoEl();
            if (video) {
                video.classList.remove('hidden');
            }

            // Reset buttons
            this.captureBtn()?.classList.remove('hidden');
            this.setCaptureEnabled(false);
            this.retakeBtn()?.classList.add('hidden');
            this.usePhotoBtn()?.classList.add('hidden');

            this.resetPipeline();
            this.setPipelineStep('ai', isDetectionReady());
            this.setPipelineStep('camera', this.isCameraActive());
            if (!this.isCameraActive()) {
                this.setLoadingPhase(
                    'camera',
                    'Kamera dan model AI sedang dimuat. Mohon tunggu...',
                    'loading',
                );
            }
            if (!isDetectionReady()) {
                this.setLoadingPhase('ai', 'Model AI sedang dimuat. Mohon tunggu...', 'loading');
            }
            const aiPromise = this.ensureDetectionReady();
            // Also re-attaches a live stream whose preview froze.
            const ok = await this.startCamera();
            if (!ok) {
                this.setCaptureEnabled(false);
                return;
            }
            const aiOk = await aiPromise;
            if (aiOk) {
                this.setLoadingPhase('ready', 'Kamera aktif — siap ambil foto', 'ready');
            } else {
                this.setNotice(VERIFICATION_NOTICES.aiUnavailable);
                this.setCaptureEnabled(false);
            }
        });

        if (!this.submitListenerBound) {
            this.submitListenerBound = true;

            document.addEventListener('submit', (e) => {
                const form = e.target;

                if (!(form instanceof HTMLFormElement) || !form.matches('[data-attendance-face-form]')) {
                    return;
                }

                if (form.dataset.dualAction === '1') {
                    e.preventDefault();
                    return;
                }

                e.preventDefault();

                const reportPlainLength = getAttendanceReportPlainLength(form);

                if (reportPlainLength < MIN_REPORT_LENGTH) {
                    this.showFormAlert(
                        form,
                        VERIFICATION_NOTICES.reportIncomplete.title,
                        VERIFICATION_NOTICES.reportIncomplete.message,
                        'error',
                        'report-incomplete',
                    );
                    return;
                }

                this.open(form);
            });
        }

        if (!this.dualActionListenerBound) {
            this.dualActionListenerBound = true;

            document.addEventListener('click', (e) => {
                const button = e.target.closest('[data-attendance-action]');

                if (!button || button.disabled) {
                    return;
                }

                const form = button.closest('[data-attendance-face-form]');

                if (!form || form.dataset.dualAction !== '1') {
                    return;
                }

                e.preventDefault();
                this.beginAttendanceAction(form, button.dataset.attendanceAction);
            });
        }

        this.preloadPageLocation().catch(() => { });
        if (this.canWarmUp()) {
            this.warmUp();
            // Asks for camera permission on page load, as the modal copy promises.
            this.startCamera({ prewarm: true }).catch(() => { });
        }
        window.addEventListener('pagehide', () => this.releaseCamera());
        document.addEventListener('visibilitychange', () => {
            this.onVisibilityChange().catch(() => { });
        });
    },
};

function bootModal() {
    if (!document.getElementById('face-verification-modal')) {
        return;
    }

    FaceVerificationModal.boot();

    if (window.GeofenceMap && !window.GeofenceMap.booted) {
        window.GeofenceMap.initAll();
        window.GeofenceMap.booted = true;
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootModal);
} else {
    bootModal();
}

export default FaceVerificationModal;

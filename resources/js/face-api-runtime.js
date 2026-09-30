/**
 * Singleton loader for face-api.js (local script) and AI models.
 * The library and its models load only when face verification starts.
 *
 * Every step has a time limit, and inference falls back from WebGL to the CPU
 * backend when WebGL fails or hangs (seen on some iPhones), so a caller always
 * gets either a result or an error it can show.
 */

import { withTimeout } from './async-timeout.js';

const SCRIPT_TIMEOUT_MS = 45000;
const BACKEND_TIMEOUT_MS = 15000;
// The three models are about 7 MB; allow for a slow mobile connection.
const MODEL_TIMEOUT_MS = 90000;
const WEBGL_INFERENCE_TIMEOUT_MS = 30000;
const CPU_INFERENCE_TIMEOUT_MS = 60000;

const state = {
    detectionReady: false,
    recognitionReady: false,
    scriptPromise: null,
    backendPromise: null,
    detectionPromise: null,
    recognitionPromise: null,
    usingCpu: false,
    warmUpPromise: null,
};

export function modelPath() {
    return document.querySelector('meta[name="face-model-path"]')?.content || '/models';
}

export function createDetectorOptions() {
    // 320 finds smaller or off-centre faces far more reliably than 160, and matches
    // the size used when the face was registered.
    return new faceapi.TinyFaceDetectorOptions({
        inputSize: 320,
        scoreThreshold: 0.5,
    });
}

export function isDetectionReady() {
    return state.detectionReady;
}

export function isRecognitionReady() {
    return state.recognitionReady;
}

export function faceApiScriptUrl() {
    return document.querySelector('meta[name="face-api-script-url"]')?.content
        || '/face-api/face-api-1.7.15.js';
}

export function waitForFaceApi() {
    if (typeof faceapi !== 'undefined') {
        return Promise.resolve(true);
    }

    if (state.scriptPromise) {
        return state.scriptPromise;
    }

    const loadPromise = new Promise((resolve) => {
        let settled = false;
        let script = document.getElementById('face-api-script');

        const finish = (ok) => {
            if (settled) {
                return;
            }
            settled = true;
            clearTimeout(timer);
            script?.removeEventListener('load', onLoad);
            script?.removeEventListener('error', onError);
            resolve(ok);
        };

        const onLoad = () => {
            const ready = typeof faceapi !== 'undefined';
            if (ready) {
                window.dispatchEvent(new Event('face-api:ready'));
            }
            finish(ready);
        };

        const onError = () => {
            script?.remove();
            finish(false);
        };

        // A retry re-attaches to the same element, and succeeds at once if it loaded late.
        const timer = setTimeout(() => finish(typeof faceapi !== 'undefined'), SCRIPT_TIMEOUT_MS);

        const shouldAppend = !script;
        if (shouldAppend) {
            script = document.createElement('script');
            script.id = 'face-api-script';
            script.src = faceApiScriptUrl();
            script.async = true;
        }

        script.addEventListener('load', onLoad, { once: true });
        script.addEventListener('error', onError, { once: true });

        if (shouldAppend) {
            document.head.appendChild(script);
        }
    });

    state.scriptPromise = loadPromise.then((ready) => {
        if (!ready) {
            state.scriptPromise = null;
        }
        return ready;
    });

    return state.scriptPromise;
}

async function useCpuBackend(tf) {
    await tf.setBackend('cpu');
    await tf.ready();
    state.usingCpu = true;
}

/**
 * Picks WebGL when it initialises, otherwise the CPU backend (slower, but works everywhere).
 */
function prepareBackend() {
    if (state.backendPromise) {
        return state.backendPromise;
    }

    state.backendPromise = (async () => {
        const tf = faceapi.tf;
        if (!tf?.setBackend) {
            return 'default';
        }

        try {
            const ok = await withTimeout(tf.setBackend('webgl'), BACKEND_TIMEOUT_MS, 'WebGL');
            if (!ok) {
                throw new Error('WebGL tidak tersedia');
            }
            await withTimeout(tf.ready(), BACKEND_TIMEOUT_MS, 'WebGL');
        } catch (error) {
            console.warn('WebGL tidak tersedia, pengenal wajah memakai CPU:', error);
            await useCpuBackend(tf);
        }

        return tf.getBackend();
    })();

    return state.backendPromise;
}

export async function loadDetectionModels() {
    if (state.detectionReady) {
        return true;
    }

    if (state.detectionPromise) {
        return state.detectionPromise;
    }

    const path = modelPath();

    state.detectionPromise = prepareBackend()
        .then(() => withTimeout(Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(path),
            faceapi.nets.faceLandmark68Net.loadFromUri(path),
        ]), MODEL_TIMEOUT_MS, 'Memuat model wajah'))
        .then(() => {
            state.detectionReady = true;
            return true;
        })
        .catch((err) => {
            state.detectionPromise = null;
            console.error('face-api detection models failed:', err);
            throw err;
        });

    return state.detectionPromise;
}

export async function loadRecognitionModel() {
    if (state.recognitionReady) {
        return true;
    }

    if (state.recognitionPromise) {
        return state.recognitionPromise;
    }

    await loadDetectionModels();

    const path = modelPath();

    state.recognitionPromise = withTimeout(
        faceapi.nets.faceRecognitionNet.loadFromUri(path),
        MODEL_TIMEOUT_MS,
        'Memuat model pengenal wajah',
    )
        .then(() => {
            state.recognitionReady = true;
            return true;
        })
        .catch((err) => {
            state.recognitionPromise = null;
            console.error('face-api recognition model failed:', err);
            throw err;
        });

    return state.recognitionPromise;
}

export async function ensureDetectionReady() {
    const apiReady = await waitForFaceApi();
    if (!apiReady) {
        return false;
    }

    try {
        await loadDetectionModels();
        return true;
    } catch {
        return false;
    }
}

export async function ensureRecognitionReady() {
    const apiReady = await waitForFaceApi();
    if (!apiReady) {
        return false;
    }

    try {
        await loadRecognitionModel();
        return true;
    } catch {
        return false;
    }
}

/**
 * Moves inference to the CPU backend and reloads the models there.
 * Returns false when already on the CPU (nothing left to fall back to).
 */
async function switchToCpuBackend() {
    const tf = faceapi.tf;
    if (!tf?.setBackend || state.usingCpu) {
        return false;
    }

    [
        faceapi.nets.tinyFaceDetector,
        faceapi.nets.faceLandmark68Net,
        faceapi.nets.faceRecognitionNet,
    ].forEach((net) => {
        try {
            net.dispose();
        } catch {
            // The WebGL context may already be lost.
        }
    });

    Object.assign(state, {
        detectionReady: false,
        recognitionReady: false,
        detectionPromise: null,
        recognitionPromise: null,
    });

    await useCpuBackend(tf);
    state.backendPromise = Promise.resolve('cpu');
    await loadRecognitionModel();

    return true;
}

function inferenceTimeout() {
    return state.usingCpu ? CPU_INFERENCE_TIMEOUT_MS : WEBGL_INFERENCE_TIMEOUT_MS;
}

/**
 * Runs `task` with a time limit. On a WebGL error or hang it retries once on the CPU backend.
 */
async function withBackendFallback(task) {
    try {
        return await withTimeout(task(), inferenceTimeout(), 'Pemeriksaan wajah');
    } catch (error) {
        console.warn('Pemeriksaan wajah gagal, mencoba ulang dengan CPU:', error);
        if (!(await switchToCpuBackend())) {
            throw error;
        }
        return withTimeout(task(), inferenceTimeout(), 'Pemeriksaan wajah');
    }
}

/**
 * Detects every face with landmarks and descriptors. Requires the recognition model.
 */
export function detectFaces(input, options = createDetectorOptions()) {
    return withBackendFallback(
        () => faceapi.detectAllFaces(input, options).withFaceLandmarks().withFaceDescriptors(),
    );
}

/**
 * Runs each model once on a blank image so shaders compile (or the CPU fallback kicks in)
 * before the employee takes a photo, instead of during verification.
 */
export function warmUpInference() {
    if (state.warmUpPromise) {
        return state.warmUpPromise;
    }

    state.warmUpPromise = ensureRecognitionReady()
        .then(async (ready) => {
            if (!ready) {
                return false;
            }
            const canvas = document.createElement('canvas');
            canvas.width = 150;
            canvas.height = 150;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#808080';
            ctx.fillRect(0, 0, 150, 150);
            // A blank image has no face, so the landmark and descriptor nets are run directly.
            await withBackendFallback(async () => {
                await faceapi.detectAllFaces(canvas, createDetectorOptions());
                await faceapi.detectFaceLandmarks(canvas);
                await faceapi.computeFaceDescriptor(canvas);
            });
            return true;
        })
        .catch((error) => {
            console.warn('Pemanasan pengenal wajah gagal:', error);
            state.warmUpPromise = null;
            return false;
        });

    return state.warmUpPromise;
}

export function preloadFaceModels() {
    waitForFaceApi().then((ready) => {
        if (ready) {
            loadDetectionModels().catch(() => {});
        }
    });
}

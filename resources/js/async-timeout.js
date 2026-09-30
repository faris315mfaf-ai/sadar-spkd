/**
 * Promise helpers that guarantee an await finishes. Browser APIs (camera, GPS, WebGL,
 * speech) can stall without ever settling, especially on iPhone Safari; a stalled
 * await used to leave face verification spinning forever.
 */

export class TimeoutError extends Error {
    constructor(label, ms) {
        super(`${label} melebihi batas waktu ${Math.round(ms / 1000)} detik.`);
        this.name = 'TimeoutError';
        this.label = label;
    }
}

export function isTimeoutError(error) {
    return error?.name === 'TimeoutError';
}

/**
 * Rejects with TimeoutError when `promise` has not settled after `ms`.
 * The original work keeps running; callers must ignore its late result.
 */
export function withTimeout(promise, ms, label = 'Proses') {
    let timer = null;
    const timeout = new Promise((_, reject) => {
        timer = setTimeout(() => reject(new TimeoutError(label, ms)), ms);
    });

    return Promise.race([Promise.resolve(promise), timeout]).finally(() => clearTimeout(timer));
}

export function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Loads an image element, failing instead of waiting forever on a broken or slow URL.
 */
export function loadImage(src, { timeoutMs = 15000, crossOrigin = null } = {}) {
    const image = new Image();

    const loaded = new Promise((resolve, reject) => {
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('Gambar tidak dapat dimuat.'));
    });

    if (crossOrigin) {
        image.crossOrigin = crossOrigin;
    }
    image.src = src;

    return withTimeout(loaded, timeoutMs, 'Memuat gambar');
}

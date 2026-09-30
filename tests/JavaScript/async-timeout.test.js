import assert from 'node:assert/strict';
import test from 'node:test';

import { isTimeoutError, TimeoutError, withTimeout } from '../../resources/js/async-timeout.js';

test('withTimeout passes through a result that arrives in time', async () => {
    assert.equal(await withTimeout(Promise.resolve('ok'), 50, 'Uji'), 'ok');
});

test('withTimeout rejects a promise that never settles', async () => {
    await assert.rejects(
        withTimeout(new Promise(() => {}), 20, 'Pemeriksaan wajah'),
        (error) => error instanceof TimeoutError
            && isTimeoutError(error)
            && error.message.includes('Pemeriksaan wajah'),
    );
});

test('withTimeout keeps the original error', async () => {
    await assert.rejects(withTimeout(Promise.reject(new Error('kamera')), 50), /kamera/);
});

test('speak resolves even when the browser never ends the utterance (iPhone Safari)', async () => {
    // Speech engine that accepts utterances but never fires onend or onerror.
    globalThis.window = {
        speechSynthesis: {
            speaking: false,
            speak() {},
            cancel() {},
            pause() {},
            resume() {},
            getVoices: () => [{ lang: 'id-ID' }],
            addEventListener() {},
            removeEventListener() {},
        },
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
    };
    globalThis.SpeechSynthesisUtterance = class {
        constructor(text) {
            this.text = text;
        }
    };

    const { speak } = await import('../../resources/js/speak.js');
    const started = Date.now();
    await speak('Wajah tidak terdeteksi');

    assert.ok(Date.now() - started < 8000, 'speak() must give up instead of waiting forever');

    delete globalThis.window;
    delete globalThis.SpeechSynthesisUtterance;
});

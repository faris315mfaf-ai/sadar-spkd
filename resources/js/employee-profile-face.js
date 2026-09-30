import { loadImage } from './async-timeout.js';
import { createDetectorOptions, detectFaces, ensureRecognitionReady } from './face-api-runtime.js';

function descriptorFieldForMode(mode) {
    return document.getElementById(mode === 'edit' ? 'edit_face_descriptor_json' : 'face_descriptor_json');
}

function descriptorStatusForMode(mode) {
    return document.getElementById(mode === 'edit' ? 'edit_face_descriptor_status' : 'face_descriptor_status');
}

function setDescriptorStatus(mode, message, isError = false) {
    const el = descriptorStatusForMode(mode);

    if (!el) {
        return;
    }

    el.textContent = message;
    el.classList.remove('hidden', 'text-green-600', 'text-red-600', 'dark:text-green-400', 'dark:text-red-400');
    el.classList.add(isError ? 'text-red-600' : 'text-green-600', 'dark:' + (isError ? 'text-red-400' : 'text-green-400'));
}

export async function extractFaceDescriptorFromFile(file, mode) {
    const field = descriptorFieldForMode(mode);

    if (!field) {
        return;
    }

    field.value = '';
    setDescriptorStatus(mode, 'Memproses wajah dari foto...', false);

    if (!file || typeof faceapi === 'undefined') {
        setDescriptorStatus(mode, '', false);
        return;
    }

    const ready = await ensureRecognitionReady();

    if (!ready) {
        setDescriptorStatus(mode, 'Model AI belum siap. Simpan foto lalu coba lagi dari halaman absensi.', true);
        return;
    }

    const objectUrl = URL.createObjectURL(file);
    let detections;

    try {
        const image = await loadImage(objectUrl);
        detections = await detectFaces(image, createDetectorOptions());
    } catch (error) {
        console.error('Foto karyawan gagal diproses:', error);
        setDescriptorStatus(mode, 'Foto tidak dapat diproses. Gunakan foto JPG/PNG lain dengan wajah yang jelas.', true);
        return;
    } finally {
        URL.revokeObjectURL(objectUrl);
    }

    if (detections.length !== 1) {
        setDescriptorStatus(
            mode,
            detections.length < 1
                ? 'Wajah tidak terdeteksi. Gunakan foto dengan wajah yang jelas.'
                : 'Hanya satu wajah yang diperbolehkan pada foto profil.',
            true,
        );
        return;
    }

    field.value = JSON.stringify(Array.from(detections[0].descriptor));
    setDescriptorStatus(mode, 'Wajah berhasil diproses dari foto profil.', false);
}

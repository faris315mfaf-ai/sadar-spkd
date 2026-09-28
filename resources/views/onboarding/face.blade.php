<x-guest-layout>
    <x-slot name="title">Daftar Wajah</x-slot>

    <div class="mb-6">
        <span class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-brand-600 dark:bg-brand-900/20 dark:text-brand-400">
            <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
            Langkah 3 dari 4
        </span>
        <h2 class="mt-4 text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">Daftarkan wajah Anda</h2>
        <p class="mt-2 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
            Foto ini menjadi foto profil dan acuan verifikasi wajah setiap kali Anda absen.
        </p>
    </div>

    <x-onboarding.steps :current="3" />

    <div id="onboarding-face" data-store-url="{{ route('onboarding.face.store') }}" class="space-y-4">
        <ul class="space-y-1.5 rounded-2xl border border-gray-100 bg-gray-50/80 p-4 text-xs leading-relaxed text-gray-600 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300">
            <li>• Cari tempat yang terang, wajah menghadap lurus ke kamera.</li>
            <li>• Lepas masker, topi, dan kacamata gelap.</li>
            <li>• Pastikan hanya wajah Anda yang terlihat.</li>
        </ul>

        <div class="relative aspect-[4/3] overflow-hidden rounded-2xl bg-gray-900">
            <video id="onboarding-face-video" class="hidden h-full w-full -scale-x-100 object-cover" playsinline muted autoplay></video>
            <img id="onboarding-face-preview" class="hidden h-full w-full -scale-x-100 object-cover" alt="Foto wajah yang diambil">
            <div id="onboarding-face-guide" class="pointer-events-none absolute inset-0 hidden items-center justify-center">
                <div class="h-[78%] w-[46%] rounded-[50%] border-2 border-dashed border-white/70"></div>
            </div>
            <div id="onboarding-face-placeholder" class="absolute inset-0 flex flex-col items-center justify-center gap-2 text-gray-400">
                <svg class="h-10 w-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h1.5l1.2-1.8A2 2 0 019.4 4h5.2a2 2 0 011.7.9L17.5 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9Z" />
                    <circle cx="12" cy="13" r="3.5" stroke-width="1.5" />
                </svg>
                <span class="text-xs">Kamera belum aktif</span>
            </div>
        </div>

        <p id="onboarding-face-status" role="status" aria-live="polite"
            class="min-h-5 text-center text-sm text-gray-500 dark:text-gray-400">
            Tekan "Aktifkan Kamera" untuk memulai.
        </p>

        <div class="grid gap-2">
            <button type="button" id="onboarding-face-start"
                class="inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-brand-600 px-5 py-3 text-sm font-semibold text-white shadow-md shadow-brand-600/20 transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60">
                Aktifkan Kamera
            </button>
            <button type="button" id="onboarding-face-capture" disabled
                class="hidden min-h-12 w-full items-center justify-center rounded-xl bg-brand-600 px-5 py-3 text-sm font-semibold text-white shadow-md shadow-brand-600/20 transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60">
                Ambil Foto
            </button>
            <button type="button" id="onboarding-face-save"
                class="hidden min-h-12 w-full items-center justify-center rounded-xl bg-emerald-600 px-5 py-3 text-sm font-semibold text-white shadow-md shadow-emerald-600/20 transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60">
                Simpan Wajah
            </button>
            <button type="button" id="onboarding-face-retake"
                class="hidden min-h-11 w-full items-center justify-center rounded-xl border border-gray-200 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                Ulangi Foto
            </button>
        </div>
    </div>
</x-guest-layout>

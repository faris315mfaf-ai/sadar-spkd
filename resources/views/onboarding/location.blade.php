<x-guest-layout>
    <x-slot name="title">Cek Lokasi</x-slot>

    <div class="mb-6">
        <span class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-brand-600 dark:bg-brand-900/20 dark:text-brand-400">
            <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
            Langkah 4 dari 4
        </span>
        <h2 class="mt-4 text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">Izinkan akses lokasi</h2>
        <p class="mt-2 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
            Absensi memeriksa apakah Anda berada di area kantor. Uji sekarang agar saat absen tidak ada kendala.
        </p>
    </div>

    <x-onboarding.steps :current="4" />

    <div id="onboarding-location"
        data-office-lat="{{ $hasGeofence ? $officeLatitude : '' }}"
        data-office-lng="{{ $hasGeofence ? $officeLongitude : '' }}"
        data-radius="{{ $hasGeofence ? $radiusMeters : '' }}"
        class="space-y-4">
        <div class="rounded-2xl border border-gray-100 bg-gray-50/80 p-4 text-xs leading-relaxed text-gray-600 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300">
            @if ($hasGeofence)
                Radius absensi kantor: <strong>{{ $radiusMeters >= 1000 ? rtrim(rtrim(number_format($radiusMeters / 1000, 2, ',', '.'), '0'), ',').' KM' : $radiusMeters.' meter' }}</strong>.
                Aktifkan GPS di HP dan pilih <strong>Izinkan</strong> saat browser meminta akses lokasi.
            @else
                Kantor belum mengatur radius absensi. Tetap izinkan akses lokasi karena lokasi dicatat saat absen.
            @endif
        </div>

        <div id="onboarding-location-result" role="status" aria-live="polite" class="hidden rounded-2xl border p-4 text-sm"></div>

        <div class="grid gap-2">
            <button type="button" id="onboarding-location-check"
                class="inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-brand-600 px-5 py-3 text-sm font-semibold text-white shadow-md shadow-brand-600/20 transition hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60">
                Cek Lokasi Saya
            </button>
            <a href="{{ route('attendance.index') }}" id="onboarding-location-finish"
                class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-gray-200 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200">
                Selesai, ke Halaman Absensi
            </a>
        </div>
    </div>
</x-guest-layout>

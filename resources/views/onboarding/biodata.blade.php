<x-guest-layout>
    <x-slot name="title">Biodata</x-slot>

    <div class="mb-6">
        <span class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-brand-600 dark:bg-brand-900/20 dark:text-brand-400">
            <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
            Langkah 2 dari 4
        </span>
        <h2 class="mt-4 text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">Lengkapi biodata</h2>
        <p class="mt-2 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
            Data ini dipakai HR untuk administrasi absensi. Pastikan sesuai KTP.
        </p>
    </div>

    <x-onboarding.steps :current="2" />

    @if (session('error'))
        <div role="alert" class="mb-5 rounded-2xl border border-red-200 bg-red-50/80 p-4 text-sm text-red-700 dark:border-red-900/60 dark:bg-red-950/25 dark:text-red-300">
            {{ session('error') }}
        </div>
    @endif

    <form method="POST" action="{{ route('onboarding.biodata.store') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="name" value="Nama Lengkap" class="font-semibold" />
            <x-text-input id="name"
                class="mt-2 block min-h-12 w-full rounded-xl border-gray-200 bg-gray-50/60 text-sm focus:border-brand-500 focus:bg-white focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900"
                type="text" name="name" :value="old('name')" required autofocus autocomplete="name" maxlength="255"
                placeholder="Sesuai KTP" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="staff" value="Divisi" class="font-semibold" />
            <x-text-input id="staff"
                class="mt-2 block min-h-12 w-full rounded-xl border-gray-200 bg-gray-50/60 text-sm focus:border-brand-500 focus:bg-white focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900"
                type="text" name="staff" :value="old('staff')" required maxlength="100" autocomplete="organization-title"
                placeholder="Contoh: IT, Keuangan, Security" />
            <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                Tulis <strong>Security</strong>, <strong>OB</strong>, atau <strong>Engineering</strong> jika Anda bekerja dengan jadwal shift khusus.
            </p>
            <x-input-error :messages="$errors->get('staff')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="nik" value="NIK" class="font-semibold" />
            <x-text-input id="nik"
                class="mt-2 block min-h-12 w-full rounded-xl border-gray-200 bg-gray-50/60 text-sm tracking-wider focus:border-brand-500 focus:bg-white focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900"
                type="text" name="nik" :value="old('nik')" required inputmode="numeric" maxlength="16"
                pattern="\d{16}" autocomplete="off" placeholder="16 digit sesuai KTP"
                oninput="this.value = this.value.replace(/\D/g, '').slice(0, 16)" />
            <x-input-error :messages="$errors->get('nik')" class="mt-2" />
        </div>

        <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-brand-600 px-5 py-3 text-sm font-semibold text-white shadow-md shadow-brand-600/20 transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 active:scale-[0.99]">
            Simpan &amp; Lanjut
        </button>
    </form>

    <div class="mt-5 text-center text-xs text-gray-400">
        Masuk sebagai {{ auth()->user()->email }}.
        <form method="POST" action="{{ route('logout') }}" class="inline">
            @csrf
            <button type="submit" class="font-semibold text-gray-500 underline hover:text-gray-700">Keluar</button>
        </form>
    </div>
</x-guest-layout>

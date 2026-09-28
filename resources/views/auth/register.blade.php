<x-guest-layout>
    <x-slot name="title">Daftar</x-slot>

    <div class="mb-6">
        <span class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider text-brand-600 dark:bg-brand-900/20 dark:text-brand-400">
            <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
            Pendaftaran karyawan
        </span>
        <h2 class="mt-4 text-2xl font-bold tracking-tight text-gray-900 dark:text-gray-100">Buat akun Anda</h2>
        <p class="mt-2 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
            Daftar dengan email dan kata sandi. Setelah itu lengkapi biodata dan daftarkan wajah Anda untuk absensi.
        </p>
    </div>

    <x-onboarding.steps :current="1" />

    <form method="POST" action="{{ route('register') }}" class="space-y-5">
        @csrf

        <div>
            <x-input-label for="email" value="Alamat Email" class="font-semibold" />
            <div class="relative mt-2">
                <span class="pointer-events-none absolute inset-y-0 left-0 flex w-11 items-center justify-center text-gray-400">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2Z" />
                    </svg>
                </span>
                <x-text-input id="email"
                    class="block min-h-12 w-full rounded-xl border-gray-200 bg-gray-50/60 pl-11 text-sm focus:border-brand-500 focus:bg-white focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900 dark:focus:border-brand-500 dark:focus:ring-brand-500"
                    type="email" name="email" :value="old('email')" required autofocus autocomplete="email"
                    placeholder="nama@email.com" />
            </div>
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password" value="Kata Sandi" class="font-semibold" />
            <x-password-input id="password"
                class="mt-2 block min-h-12 w-full rounded-xl border-gray-200 bg-gray-50/60 text-sm focus:border-brand-500 focus:bg-white focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900 dark:focus:border-brand-500 dark:focus:ring-brand-500"
                name="password" required autocomplete="new-password" minlength="8"
                placeholder="Minimal 8 karakter, huruf dan angka" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="password_confirmation" value="Ulangi Kata Sandi" class="font-semibold" />
            <x-password-input id="password_confirmation"
                class="mt-2 block min-h-12 w-full rounded-xl border-gray-200 bg-gray-50/60 text-sm focus:border-brand-500 focus:bg-white focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900 dark:focus:border-brand-500 dark:focus:ring-brand-500"
                name="password_confirmation" required autocomplete="new-password"
                placeholder="Ketik ulang kata sandi" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <button type="submit" class="group relative inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-brand-600 px-5 py-3 text-sm font-semibold text-white shadow-md shadow-brand-600/20 transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 active:scale-[0.99] dark:bg-brand-700 dark:hover:bg-brand-600 dark:focus:ring-offset-gray-800">
            Daftar
            <span class="absolute right-3 inline-flex h-7 w-7 items-center justify-center rounded-lg bg-white/10">
                <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m9 5 7 7-7 7" />
                </svg>
            </span>
        </button>

        <p class="text-center text-sm text-gray-500 dark:text-gray-400">
            Sudah punya akun?
            <a href="{{ route('login') }}" class="font-semibold text-brand-600 hover:text-brand-800 dark:text-brand-400 dark:hover:text-brand-300">Masuk</a>
        </p>
    </form>
</x-guest-layout>

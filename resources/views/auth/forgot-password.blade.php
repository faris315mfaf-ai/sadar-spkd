<x-guest-layout>
    <div class="mb-6">
        <h2 class="text-xl font-bold text-gray-900 dark:text-gray-100">Lupa Kata Sandi</h2>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Masukkan alamat email Anda dan kami akan mengirimkan tautan untuk mereset kata sandi.</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <!-- Email -->
        <div>
            <x-input-label for="email" value="Alamat Email" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus placeholder="contoh@email.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between">
            <a href="{{ route('login') }}"
                class="group inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl border border-gray-200 bg-white px-4 py-2.5 text-sm font-semibold text-gray-600 shadow-sm transition hover:border-brand-200 hover:bg-brand-50 hover:text-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 sm:w-auto dark:border-gray-600 dark:bg-gray-800 dark:text-gray-300 dark:hover:border-brand-900 dark:hover:bg-brand-950/20 dark:hover:text-brand-300 dark:focus:ring-offset-gray-800">
                <svg class="h-4 w-4 transition-transform group-hover:-translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M10 19l-7-7m0 0 7-7m-7 7h18" />
                </svg>
                Kembali ke Login
            </a>

            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 sm:w-auto dark:bg-brand-700 dark:hover:bg-brand-600 dark:focus:ring-offset-gray-800">
                Kirim Tautan Reset
            </button>
        </div>
    </form>
</x-guest-layout>

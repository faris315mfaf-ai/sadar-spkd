<x-app-layout>
    <div class="py-6">
        <div class="mx-auto max-w-2xl space-y-5 px-4 sm:px-6 lg:px-8">
            <a href="{{ route('admin.accounts.index') }}"
                class="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-500 transition hover:text-brand-700 dark:text-gray-400 dark:hover:text-brand-300">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                </svg>
                Kembali ke daftar akun
            </a>

            <section class="relative overflow-hidden rounded-[1.75rem] border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-brand-600 via-brand-400 to-navy-700"></div>
                <div class="px-5 py-5 sm:px-6 sm:py-6">
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Ganti Password</h1>
                    <div class="mt-4 rounded-2xl bg-gray-50 px-4 py-3 dark:bg-gray-900/40">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $account->name }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $account->email }}</p>
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                            {{ $account->roles->map(fn ($role) => $role->label ?? $role->name)->join(', ') ?: 'Tanpa peran' }}
                            @if ($account->employee)
                                · {{ $account->employee->employee_code }}
                            @endif
                        </p>
                    </div>

                    <form method="POST" action="{{ route('admin.accounts.password.update', $account) }}" class="mt-6 space-y-5"
                        x-data="{
                            password: '',
                            confirmation: '',
                            generated: false,
                            copied: false,
                            generate() {
                                // Unambiguous letters and digits; always at least one of each.
                                const letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
                                const digits = '23456789';
                                const pool = letters + digits;
                                const random = new Uint32Array(12);
                                crypto.getRandomValues(random);
                                let value = '';
                                random.forEach((n, i) => {
                                    const set = i === 0 ? letters : (i === 1 ? digits : pool);
                                    value += set[n % set.length];
                                });
                                this.password = value;
                                this.confirmation = value;
                                this.generated = true;
                                this.copied = false;
                            },
                            async copy() {
                                try {
                                    await navigator.clipboard.writeText(this.password);
                                    this.copied = true;
                                } catch {
                                    this.copied = false;
                                }
                            },
                        }">
                        @csrf
                        @method('PUT')

                        <div>
                            <div class="flex items-center justify-between gap-3">
                                <x-input-label for="password" value="Password baru" />
                                <button type="button" @click="generate()"
                                    class="text-xs font-semibold text-brand-700 hover:text-brand-800 dark:text-brand-300">
                                    Buat password acak
                                </button>
                            </div>
                            <x-password-input id="password" name="password" x-model="password" required autocomplete="new-password"
                                class="mt-1 block w-full" />
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Minimal 8 karakter, berisi huruf dan angka.</p>
                            <x-input-error :messages="$errors->get('password')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="password_confirmation" value="Ulangi password baru" />
                            <x-password-input id="password_confirmation" name="password_confirmation" x-model="confirmation" required autocomplete="new-password"
                                class="mt-1 block w-full" />
                        </div>

                        <div x-show="generated" x-cloak
                            class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-brand-100 bg-brand-50 px-4 py-3 dark:border-brand-900/50 dark:bg-brand-950/30">
                            <div>
                                <p class="text-xs font-semibold text-brand-800 dark:text-brand-200">Password acak</p>
                                <p class="font-mono text-base font-bold tracking-wider text-gray-900 dark:text-white" x-text="password"></p>
                            </div>
                            <button type="button" @click="copy()"
                                class="inline-flex min-h-9 items-center rounded-xl border border-brand-200 bg-white px-3 text-xs font-semibold text-brand-700 transition hover:bg-brand-50 dark:border-brand-800 dark:bg-gray-800 dark:text-brand-300"
                                x-text="copied ? 'Tersalin' : 'Salin'"></button>
                        </div>

                        <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                            Setelah disimpan, akun ini otomatis keluar dari semua perangkat. Berikan password baru langsung ke pemiliknya
                            (jangan lewat grup), lalu minta ia menggantinya sendiri di halaman Profil.
                        </div>

                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <a href="{{ route('admin.accounts.index') }}"
                                class="inline-flex min-h-11 items-center justify-center rounded-2xl border border-gray-200 px-5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                Batal
                            </a>
                            <button type="submit"
                                class="inline-flex min-h-11 items-center justify-center rounded-2xl bg-brand-600 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                                Simpan Password Baru
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </div>
</x-app-layout>

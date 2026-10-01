<x-app-layout>
    <div class="py-6">
        <div class="mx-auto space-y-5 px-4 sm:px-6 lg:px-8">
            <section class="relative overflow-hidden rounded-[1.75rem] border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-brand-600 via-brand-400 to-navy-700"></div>
                <div class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-end sm:justify-between sm:px-6 sm:py-6">
                    <div>
                        <div class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-700 ring-1 ring-inset ring-brand-100 dark:bg-brand-950/40 dark:text-brand-300 dark:ring-brand-900/50">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Khusus Superadmin
                        </div>
                        <h1 class="mt-3 text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Akun &amp; Password</h1>
                        <p class="mt-1.5 max-w-2xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            Ganti password akun karyawan, HR, atau admin lain, misalnya saat lupa password atau masih memakai password bawaan.
                        </p>
                    </div>
                    <form method="GET" action="{{ route('admin.accounts.index') }}" class="flex w-full gap-2 sm:w-auto">
                        <label for="account-search" class="sr-only">Cari akun</label>
                        <input id="account-search" type="search" name="search" value="{{ $search }}"
                            placeholder="Cari nama, email, atau kode karyawan"
                            class="min-h-11 w-full rounded-2xl border-gray-200 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:w-80 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <button type="submit"
                            class="inline-flex min-h-11 shrink-0 items-center justify-center rounded-2xl bg-brand-600 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                            Cari
                        </button>
                    </form>
                </div>
            </section>

            @if ($users->isEmpty())
                <div class="rounded-[1.75rem] border border-dashed border-gray-300 bg-white px-6 py-12 text-center dark:border-gray-600 dark:bg-gray-800">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">Akun tidak ditemukan</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Coba kata kunci lain.</p>
                </div>
            @else
                <section class="overflow-hidden rounded-[1.5rem] border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($users as $account)
                            @php($isSelf = $account->is(auth()->user()))
                            <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="truncate text-sm font-semibold text-gray-900 dark:text-white">{{ $account->name }}</p>
                                        @foreach ($account->roles as $role)
                                            <span @class([
                                                'rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                                'bg-navy-50 text-navy-700 dark:bg-navy-950/50 dark:text-navy-200' => $role->name === 'admin',
                                                'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300' => $role->name === 'hr',
                                                'bg-brand-50 text-brand-700 dark:bg-brand-950/40 dark:text-brand-300' => ! in_array($role->name, ['admin', 'hr'], true),
                                            ])>{{ $role->label ?? $role->name }}</span>
                                        @endforeach
                                        @if ($isSelf)
                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-700 dark:text-gray-300">Akun Anda</span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{{ $account->email }}</p>
                                    @if ($account->employee)
                                        <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                                            {{ $account->employee->employee_code }}{{ $account->employee->position ? ' · '.$account->employee->position : '' }}
                                        </p>
                                    @endif
                                </div>
                                <a href="{{ $isSelf ? route('profile.edit') : route('admin.accounts.password.edit', $account) }}"
                                    class="inline-flex min-h-10 shrink-0 items-center justify-center gap-2 rounded-xl border border-gray-200 px-4 text-xs font-semibold text-gray-700 transition hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                                    </svg>
                                    {{ $isSelf ? 'Ganti di Profil' : 'Ganti Password' }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>

                {{ $users->links() }}
            @endif
        </div>
    </div>
</x-app-layout>

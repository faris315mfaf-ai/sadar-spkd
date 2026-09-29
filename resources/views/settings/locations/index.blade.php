<x-app-layout>
    <div class="py-6">
        <div class="mx-auto space-y-5 px-4 sm:px-6 lg:px-8">
            <section class="relative overflow-hidden rounded-[1.75rem] border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-brand-600 via-brand-400 to-navy-700"></div>
                <div class="flex flex-col gap-4 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6 sm:py-6">
                    <div>
                        <div class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-700 ring-1 ring-inset ring-brand-100 dark:bg-brand-950/40 dark:text-brand-300 dark:ring-brand-900/50">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Geofence Absensi
                        </div>
                        <h1 class="mt-3 text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">Lokasi Absensi</h1>
                        <p class="mt-1.5 max-w-2xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            Karyawan bisa absen di lokasi yang berlaku untuk semua karyawan, ditambah lokasi yang khusus ditugaskan kepadanya (misalnya rumah sakit).
                        </p>
                    </div>
                    <a href="{{ route('settings.locations.create') }}"
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-2xl bg-brand-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Tambah Lokasi
                    </a>
                </div>
            </section>

            @if (session('success'))
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
                    {{ session('success') }}
                </div>
            @endif

            @if ($locations->isEmpty())
                <div class="rounded-[1.75rem] border border-dashed border-gray-300 bg-white px-6 py-12 text-center dark:border-gray-600 dark:bg-gray-800">
                    <p class="text-base font-semibold text-gray-900 dark:text-white">Belum ada lokasi absensi</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Selama belum ada lokasi, karyawan bisa absen dari mana saja (lokasi tetap dicatat).</p>
                </div>
            @else
                <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
                    <section class="space-y-3">
                        @foreach ($locations as $location)
                            <article class="rounded-[1.5rem] border border-gray-200/80 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800 {{ $location->is_active ? '' : 'opacity-70' }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h2 class="truncate text-base font-bold text-gray-900 dark:text-white">{{ $location->name }}</h2>
                                            @unless ($location->is_active)
                                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-700 dark:text-gray-300">Nonaktif</span>
                                            @endunless
                                        </div>
                                        <p class="mt-1 text-xs tabular-nums text-gray-500 dark:text-gray-400">
                                            {{ number_format($location->latitude, 6) }}, {{ number_format($location->longitude, 6) }} · radius {{ $location->formattedRadius() }}
                                        </p>
                                        <p class="mt-2">
                                            @if ($location->applies_to_all)
                                                <span class="inline-flex items-center rounded-full bg-brand-50 px-2.5 py-1 text-[11px] font-semibold text-brand-700 ring-1 ring-inset ring-brand-100 dark:bg-brand-950/40 dark:text-brand-300 dark:ring-brand-900/50">Semua karyawan</span>
                                            @else
                                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-100 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900/50">{{ $location->employees_count }} karyawan tertentu</span>
                                            @endif
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-1">
                                        <a href="{{ route('settings.locations.edit', $location) }}"
                                            class="inline-flex min-h-9 items-center rounded-xl border border-gray-200 px-3 text-xs font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700">
                                            Ubah
                                        </a>
                                        <form method="POST" action="{{ route('settings.locations.destroy', $location) }}"
                                            onsubmit="return confirm({{ Js::from('Hapus lokasi "'.$location->name.'"? Riwayat absensi tetap tersimpan.') }})">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                class="inline-flex min-h-9 items-center rounded-xl border border-red-200 px-3 text-xs font-semibold text-red-600 transition hover:bg-red-50 dark:border-red-900/60 dark:text-red-400 dark:hover:bg-red-950/30">
                                                Hapus
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </section>

                    <section class="overflow-hidden rounded-[1.75rem] border border-gray-200/80 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-800 sm:p-4">
                        <x-attendance.geofence-scripts />
                        <x-attendance.geofence-map map-id="settings-locations-map" :places="$mapPoints" height-class="h-[24rem] sm:h-[32rem]" />
                    </section>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>

<x-app-layout>
    @php
        $latitude = (float) old('latitude', $location->latitude);
        $longitude = (float) old('longitude', $location->longitude);
        $radius = (int) old('radius_meters', $location->radius_meters);
        $appliesToAll = (bool) old('applies_to_all', $location->applies_to_all);
        $isActive = (bool) old('is_active', $location->is_active);
        $selectedIds = array_map('intval', (array) old('employee_ids', $assignedIds));
        $presets = [100, 250, 500, 1000, 2000, 3000];
    @endphp

    <div class="py-6">
        <div
            class="mx-auto space-y-5 px-4 sm:px-6 lg:px-8"
            x-data="{
                lat: {{ Js::from($latitude) }},
                lng: {{ Js::from($longitude) }},
                radius: {{ $radius }},
                appliesToAll: {{ $appliesToAll ? 'true' : 'false' }},
                search: '',
                matches(label) {
                    return label.toLowerCase().includes(this.search.trim().toLowerCase());
                },
                radiusLabel() {
                    const value = Number(this.radius) || 0;
                    if (value >= 1000 && value % 1000 === 0) {
                        return (value / 1000) + ' km';
                    }
                    return new Intl.NumberFormat('id-ID').format(value) + ' m';
                },
                syncRadiusFromInput() {
                    const input = document.getElementById('attendance_radius_meters');
                    this.radius = parseInt(input?.value || '0', 10) || 0;
                },
                applyPreset(value) {
                    const input = document.getElementById('attendance_radius_meters');
                    if (!input) return;
                    input.value = value;
                    this.radius = value;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }"
            x-init="
                const latInput = document.getElementById('office_latitude');
                const lngInput = document.getElementById('office_longitude');
                const radiusInput = document.getElementById('attendance_radius_meters');
                latInput?.addEventListener('input', (e) => lat = e.target.value);
                lngInput?.addEventListener('input', (e) => lng = e.target.value);
                radiusInput?.addEventListener('input', () => syncRadiusFromInput());
                radiusInput?.addEventListener('change', () => syncRadiusFromInput());
            ">

            {{-- Header --}}
            <section class="relative overflow-hidden rounded-[1.75rem] border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-brand-600 via-brand-400 to-navy-700"></div>
                <div class="grid gap-5 px-5 py-5 sm:px-6 sm:py-6 lg:grid-cols-[minmax(0,1.2fr)_auto] lg:items-center">
                    <div>
                        <div class="inline-flex items-center gap-2 rounded-full bg-brand-50 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-700 ring-1 ring-inset ring-brand-100 dark:bg-brand-950/40 dark:text-brand-300 dark:ring-brand-900/50">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            Geofence Absensi
                        </div>
                        <h1 class="mt-3 text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">
                            {{ $location->exists ? 'Ubah Lokasi Absensi' : 'Tambah Lokasi Absensi' }}
                        </h1>
                        <p class="mt-1.5 max-w-2xl text-sm leading-relaxed text-gray-500 dark:text-gray-400">
                            Tentukan titik pusat dan radius, lalu pilih siapa yang boleh absen di lokasi ini. Geser pin di peta atau isi koordinat secara manual.
                        </p>
                    </div>

                    <div class="grid grid-cols-3 gap-2 sm:gap-3">
                        <div class="rounded-2xl border border-gray-100 bg-gray-50 px-3 py-3 dark:border-gray-700 dark:bg-gray-900/50">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Latitude</p>
                            <p class="mt-1 truncate text-sm font-bold tabular-nums text-gray-900 dark:text-white" x-text="Number(lat).toFixed(6)"></p>
                        </div>
                        <div class="rounded-2xl border border-gray-100 bg-gray-50 px-3 py-3 dark:border-gray-700 dark:bg-gray-900/50">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-gray-400">Longitude</p>
                            <p class="mt-1 truncate text-sm font-bold tabular-nums text-gray-900 dark:text-white" x-text="Number(lng).toFixed(6)"></p>
                        </div>
                        <div class="rounded-2xl border border-brand-100 bg-brand-50 px-3 py-3 dark:border-brand-900/40 dark:bg-brand-950/30">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-brand-500 dark:text-brand-300">Radius</p>
                            <p class="mt-1 truncate text-sm font-bold tabular-nums text-brand-700 dark:text-brand-200" x-text="radiusLabel()"></p>
                        </div>
                    </div>
                </div>
            </section>

            <form method="POST"
                action="{{ $location->exists ? route('settings.locations.update', $location) : route('settings.locations.store') }}"
                class="space-y-5">
                @csrf
                @if ($location->exists)
                    @method('PATCH')
                @endif

                @if (session('success'))
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">
                        {{ session('success') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
                        <p class="font-semibold">Periksa kembali isian berikut:</p>
                        <ul class="mt-2 list-disc space-y-1 pl-4">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="grid gap-5 xl:grid-cols-[22rem_minmax(0,1fr)]">
                    {{-- Controls --}}
                    <aside class="space-y-4">
                        <section class="overflow-hidden rounded-[1.75rem] border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                                <h2 class="text-base font-bold tracking-tight text-gray-900 dark:text-white">Pengaturan Titik</h2>
                                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">Koordinat pusat dan jarak absensi.</p>
                            </div>

                            <div class="space-y-4 p-5">
                                <label for="name" class="block">
                                    <span class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Nama Lokasi <span class="text-red-500">*</span>
                                    </span>
                                    <input id="name" type="text" name="name" required maxlength="100"
                                        value="{{ old('name', $location->name) }}" placeholder="Contoh: Kantor Pusat, RS Harapan"
                                        class="block min-h-11 w-full rounded-2xl border-gray-200 bg-gray-50 text-sm font-semibold text-gray-900 shadow-sm transition focus:border-brand-500 focus:bg-white focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                                    @error('name')
                                        <span class="mt-1.5 block text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
                                    @enderror
                                </label>

                                <label for="office_latitude" class="block">
                                    <span class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Latitude <span class="text-red-500">*</span>
                                    </span>
                                    <input id="office_latitude" type="number" step="any" name="latitude" required
                                        value="{{ $latitude }}"
                                        class="block min-h-11 w-full rounded-2xl border-gray-200 bg-gray-50 text-sm font-semibold tabular-nums text-gray-900 shadow-sm transition focus:border-brand-500 focus:bg-white focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                                    @error('latitude')
                                        <span class="mt-1.5 block text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
                                    @enderror
                                </label>

                                <label for="office_longitude" class="block">
                                    <span class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Longitude <span class="text-red-500">*</span>
                                    </span>
                                    <input id="office_longitude" type="number" step="any" name="longitude" required
                                        value="{{ $longitude }}"
                                        class="block min-h-11 w-full rounded-2xl border-gray-200 bg-gray-50 text-sm font-semibold tabular-nums text-gray-900 shadow-sm transition focus:border-brand-500 focus:bg-white focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                                    @error('longitude')
                                        <span class="mt-1.5 block text-sm text-red-600 dark:text-red-400">{{ $message }}</span>
                                    @enderror
                                </label>

                                <div>
                                    <label for="attendance_radius_meters" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                        Radius Absensi (meter) <span class="text-red-500">*</span>
                                    </label>
                                    <input id="attendance_radius_meters" type="number" min="10" max="50000" step="1" name="radius_meters" required
                                        value="{{ $radius }}"
                                        class="block min-h-11 w-full rounded-2xl border-gray-200 bg-gray-50 text-sm font-semibold tabular-nums text-gray-900 shadow-sm transition focus:border-brand-500 focus:bg-white focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white">
                                    <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Lingkaran di peta mengikuti nilai ini.</p>
                                    @error('radius_meters')
                                        <p class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div>
                                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Preset cepat</p>
                                    <div class="grid grid-cols-3 gap-2">
                                        @foreach ($presets as $preset)
                                            <button
                                                type="button"
                                                data-radius-preset="{{ $preset }}"
                                                @click="applyPreset({{ $preset }})"
                                                :class="radius === {{ $preset }}
                                                    ? 'border-brand-500 bg-brand-600 text-white shadow-sm shadow-brand-900/10'
                                                    : 'border-gray-200 bg-white text-gray-700 hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 dark:hover:border-brand-700 dark:hover:bg-brand-950/40 dark:hover:text-brand-300'"
                                                class="rounded-xl border px-2.5 py-2 text-xs font-bold transition">
                                                {{ $preset >= 1000 && $preset % 1000 === 0 ? ($preset / 1000).' KM' : $preset.' m' }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="overflow-hidden rounded-[1.75rem] border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                            <div class="border-b border-gray-100 px-5 py-4 dark:border-gray-700">
                                <h2 class="text-base font-bold tracking-tight text-gray-900 dark:text-white">Berlaku untuk</h2>
                                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">Siapa yang boleh absen di lokasi ini.</p>
                            </div>

                            <div class="space-y-3 p-5">
                                <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition"
                                    :class="appliesToAll ? 'border-brand-500 bg-brand-50/60 dark:bg-brand-950/30' : 'border-gray-200 dark:border-gray-600'">
                                    <input type="radio" name="applies_to_all" value="1"
                                        @change="appliesToAll = true" @checked($appliesToAll)
                                        class="mt-0.5 border-gray-300 text-brand-600 focus:ring-brand-500">
                                    <span>
                                        <span class="block text-sm font-semibold text-gray-900 dark:text-white">Semua karyawan</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">Contoh: kantor utama.</span>
                                    </span>
                                </label>

                                <label class="flex cursor-pointer items-start gap-3 rounded-2xl border p-3 transition"
                                    :class="! appliesToAll ? 'border-brand-500 bg-brand-50/60 dark:bg-brand-950/30' : 'border-gray-200 dark:border-gray-600'">
                                    <input type="radio" name="applies_to_all" value="0"
                                        @change="appliesToAll = false" @checked(! $appliesToAll)
                                        class="mt-0.5 border-gray-300 text-brand-600 focus:ring-brand-500">
                                    <span>
                                        <span class="block text-sm font-semibold text-gray-900 dark:text-white">Karyawan tertentu</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">Contoh: rumah sakit, hanya untuk staf yang bertugas di sana.</span>
                                    </span>
                                </label>

                                <div x-show="! appliesToAll" x-cloak class="space-y-2">
                                    <input type="search" x-model="search" placeholder="Cari nama, kode, atau divisi"
                                        class="block min-h-10 w-full rounded-xl border-gray-200 bg-gray-50 text-sm focus:border-brand-500 focus:bg-white focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white">

                                    <div class="max-h-72 space-y-1 overflow-y-auto rounded-xl border border-gray-100 p-2 dark:border-gray-700">
                                        @forelse ($employees as $employee)
                                            @php($label = trim($employee->name.' '.$employee->employee_code.' '.$employee->staff))
                                            <label x-show="matches({{ Js::from($label) }})"
                                                class="flex cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                                <input type="checkbox" name="employee_ids[]" value="{{ $employee->id }}"
                                                    @checked(in_array($employee->id, $selectedIds, true))
                                                    :disabled="appliesToAll"
                                                    class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                                <span class="min-w-0">
                                                    <span class="block truncate text-sm font-medium text-gray-900 dark:text-white">{{ $employee->name }}</span>
                                                    <span class="block truncate text-xs text-gray-500 dark:text-gray-400">{{ $employee->employee_code }}{{ $employee->staff ? ' · '.$employee->staff : '' }}</span>
                                                </span>
                                            </label>
                                        @empty
                                            <p class="px-2 py-3 text-sm text-gray-500 dark:text-gray-400">Belum ada karyawan aktif.</p>
                                        @endforelse
                                    </div>
                                    @error('employee_ids')
                                        <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                    @enderror
                                </div>

                                <label class="flex cursor-pointer items-center gap-3 border-t border-gray-100 pt-3 dark:border-gray-700">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" name="is_active" value="1" @checked($isActive)
                                        class="rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">Lokasi aktif</span>
                                </label>
                            </div>
                        </section>
                    </aside>

                    {{-- Map --}}
                    <section class="overflow-hidden rounded-[1.75rem] border border-gray-200/80 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
                        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 dark:border-gray-700 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                            <div>
                                <h2 class="text-base font-bold tracking-tight text-gray-900 dark:text-white">Peta Lokasi</h2>
                                <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                    Marker = titik pusat · Lingkaran = radius absensi
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-1 text-[11px] font-semibold text-gray-600 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700">
                                    <span class="h-1.5 w-1.5 rounded-full bg-brand-500"></span>
                                    Titik pusat
                                </span>
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-50 px-2.5 py-1 text-[11px] font-semibold text-sky-700 ring-1 ring-inset ring-sky-100 dark:bg-sky-950/40 dark:text-sky-300 dark:ring-sky-900/50">
                                    <span class="h-1.5 w-1.5 rounded-full bg-sky-500"></span>
                                    <span x-text="'Radius ' + radiusLabel()"></span>
                                </span>
                            </div>
                        </div>

                        <div class="p-3 sm:p-4">
                            <div class="overflow-hidden rounded-2xl ring-1 ring-gray-100 dark:ring-gray-700">
                                <x-attendance.geofence-scripts />
                                <x-attendance.geofence-map
                                    map-id="settings-office-map"
                                    :office-lat="$latitude"
                                    :office-lng="$longitude"
                                    :radius-meters="$radius"
                                    :editable="true"
                                    height-class="h-[28rem] sm:h-[36rem] xl:h-[42rem]"
                                />
                            </div>
                        </div>
                    </section>
                </div>

                <div class="sticky bottom-4 z-30">
                    <div class="flex flex-col-reverse gap-3 rounded-2xl border border-gray-200/80 bg-white/95 px-4 py-3 shadow-lg shadow-gray-900/5 backdrop-blur-xl dark:border-gray-700 dark:bg-gray-900/95 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                        <p class="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
                            Perubahan langsung berlaku untuk absen berikutnya setelah disimpan.
                        </p>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row">
                            <a href="{{ route('settings.locations.index') }}"
                                class="inline-flex min-h-11 items-center justify-center rounded-2xl border border-gray-200 px-5 py-2.5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800">
                                Batal
                            </a>
                            <button type="submit"
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-2xl bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm shadow-brand-900/10 transition hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-gray-900">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                Simpan Lokasi
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>

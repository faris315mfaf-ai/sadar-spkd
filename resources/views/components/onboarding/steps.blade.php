@props(['current' => 1])

@php
    $steps = ['Akun', 'Biodata', 'Wajah', 'Lokasi'];
@endphp

<ol class="mb-7 grid grid-cols-4 gap-2" aria-label="Langkah pendaftaran">
    @foreach ($steps as $index => $label)
        @php
            $number = $index + 1;
            $done = $number < $current;
            $active = $number === $current;
        @endphp
        <li class="flex flex-col items-center gap-1.5 text-center" @if ($active) aria-current="step" @endif>
            <span @class([
                'flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold ring-2 transition',
                'bg-emerald-500 text-white ring-emerald-500' => $done,
                'bg-brand-600 text-white ring-brand-600 shadow-md shadow-brand-600/30' => $active,
                'bg-white text-gray-400 ring-gray-200 dark:bg-gray-800 dark:ring-gray-700' => ! $done && ! $active,
            ])>
                @if ($done)
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                    </svg>
                    <span class="sr-only">Selesai:</span>
                @else
                    {{ $number }}
                @endif
            </span>
            <span @class([
                'text-[11px] font-semibold',
                'text-gray-900 dark:text-gray-100' => $active,
                'text-gray-500 dark:text-gray-400' => ! $active,
            ])>{{ $label }}</span>
        </li>
    @endforeach
</ol>

@props([
    'mapId',
    'officeLat' => null,
    'officeLng' => null,
    'radiusMeters' => null,
    // Several attendance locations: list of ['name', 'lat', 'lng', 'radius'] (see WorkLocation::toMapPoint()).
    'places' => null,
    'editable' => false,
    'trackUser' => false,
    'statusTarget' => null,
    'heightClass' => 'h-80',
])

<div
    id="{{ $mapId }}"
    data-geofence-map
    @if (is_array($places))
        data-places="{{ json_encode(array_values($places)) }}"
    @else
        data-office-lat="{{ $officeLat }}"
        data-office-lng="{{ $officeLng }}"
        data-radius="{{ $radiusMeters }}"
    @endif
    data-editable="{{ $editable ? '1' : '0' }}"
    data-track-user="{{ $trackUser ? '1' : '0' }}"
    @if ($statusTarget) data-status-target="{{ $statusTarget }}" @endif
    class="z-0 {{ $heightClass }} w-full rounded-xl border border-gray-200 bg-gray-50 dark:border-gray-600 dark:bg-gray-900/50"
></div>

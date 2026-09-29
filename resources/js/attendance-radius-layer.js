/**
 * Leaflet attendance radius layer — one marker + circle per attendance location (rules: modular).
 */

const OFFICE_MARKER_HTML = `<span class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-600 text-xs font-bold text-white shadow-lg ring-2 ring-white">L</span>`;

export function createOfficeMarkerIcon() {
    return L.divIcon({
        className: 'bg-transparent border-0',
        html: OFFICE_MARKER_HTML,
        iconSize: [36, 36],
        iconAnchor: [18, 18],
        popupAnchor: [0, -20],
    });
}

/**
 * @param {L.Map} map
 * @param {Array<{ name: string, lat: number, lng: number, radius: number, radiusLabel?: string }>} places
 * @returns {{ markers: L.Marker[], circles: L.Circle[] }}
 */
export function addAttendanceRadiusLayer(map, places) {
    const markers = [];
    const circles = [];

    places.forEach((place) => {
        const lat = Number(place.lat);
        const lng = Number(place.lng);
        const radius = Number(place.radius);

        // Location names are typed by admins: build the popup with text nodes, not HTML.
        const popup = document.createElement('div');
        popup.className = 'text-sm';
        const title = document.createElement('p');
        title.className = 'font-semibold text-gray-900';
        title.textContent = place.name;
        const detail = document.createElement('p');
        detail.className = 'mt-1 text-gray-600';
        detail.textContent = `Radius absensi: ${place.radiusLabel || `${radius} meter`}`;
        popup.append(title, detail);

        markers.push(L.marker([lat, lng], { icon: createOfficeMarkerIcon() }).addTo(map).bindPopup(popup));

        circles.push(L.circle([lat, lng], {
            color: '#2563eb',
            fillColor: '#3b82f6',
            fillOpacity: 0.18,
            weight: 2,
            radius,
        }).addTo(map));
    });

    return { markers, circles };
}

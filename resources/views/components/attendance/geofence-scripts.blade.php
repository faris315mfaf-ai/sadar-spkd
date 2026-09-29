@once
    @push('styles')
        <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    @endpush

    @push('scripts')
        <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
        <script>
            window.GeofenceMap = {
                instances: {},

                formatDistance(meters) {
                    return meters >= 1000 ? (meters / 1000).toFixed(2) + ' KM' : Math.round(meters) + ' meter';
                },

                /**
                 * Attendance locations for a map container: a JSON list in data-places (several
                 * locations), or one point in data-office-lat/lng + data-radius (the location editor).
                 */
                readPlaces(container) {
                    let places;

                    if (container.dataset.places !== undefined) {
                        try {
                            places = JSON.parse(container.dataset.places) || [];
                        } catch {
                            places = [];
                        }
                    } else {
                        places = [{
                            name: container.dataset.placeName || 'Lokasi absensi',
                            lat: container.dataset.officeLat,
                            lng: container.dataset.officeLng,
                            radius: container.dataset.radius,
                        }];
                    }

                    return places
                        .map((place) => ({
                            name: String(place.name ?? 'Lokasi absensi'),
                            lat: Number(place.lat),
                            lng: Number(place.lng),
                            radius: Number(place.radius) || 0,
                        }))
                        .filter((place) => Number.isFinite(place.lat) && Number.isFinite(place.lng));
                },

                init(container) {
                    if (typeof L === 'undefined' || container.dataset.initialized === '1') {
                        return;
                    }

                    container.dataset.initialized = '1';

                    const editable = container.dataset.editable === '1';
                    const trackUser = container.dataset.trackUser === '1';
                    const statusTarget = container.dataset.statusTarget
                        ? document.getElementById(container.dataset.statusTarget)
                        : null;
                    const places = this.readPlaces(container);
                    const primary = places[0] ?? null;

                    const map = L.map(container).setView(primary ? [primary.lat, primary.lng] : [-6.2, 106.816666], 16);

                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap',
                        maxZoom: 19,
                    }).addTo(map);

                    places.forEach((place) => {
                        // Names come from admin input: build the popup as text, never as HTML.
                        const popup = document.createElement('div');
                        popup.textContent = `${place.name} · radius ${this.formatDistance(place.radius)}`;

                        place.marker = L.marker([place.lat, place.lng], { draggable: editable })
                            .addTo(map)
                            .bindPopup(popup);

                        place.circle = L.circle([place.lat, place.lng], {
                            color: '#2563eb',
                            fillColor: '#3b82f6',
                            fillOpacity: 0.2,
                            radius: place.radius,
                        }).addTo(map);
                    });

                    if (places.length > 1) {
                        map.fitBounds(L.featureGroup(places.map((place) => place.circle)).getBounds(), { padding: [24, 24] });
                    }

                    const self = this;

                    const instance = {
                        map,
                        places,
                        statusTarget,
                        userMarker: null,
                        userAccuracyCircle: null,
                        get officeMarker() {
                            return primary?.marker ?? null;
                        },
                        get radius() {
                            return primary?.radius ?? 0;
                        },
                        setRadius(newRadius) {
                            if (!primary) {
                                return;
                            }
                            primary.radius = newRadius;
                            primary.circle.setRadius(newRadius);
                        },
                        setOffice(lat, lng) {
                            if (!primary) {
                                return;
                            }
                            primary.lat = lat;
                            primary.lng = lng;
                            primary.marker.setLatLng([lat, lng]);
                            primary.circle.setLatLng([lat, lng]);
                            this.map.panTo([lat, lng]);
                        },
                        /**
                         * The location containing the point (nearest when several overlap), else the
                         * nearest one. With no locations configured every point is allowed.
                         */
                        locate(lat, lng) {
                            if (this.places.length === 0) {
                                return { withinRadius: true, distance: null, place: null };
                            }

                            const ranked = this.places
                                .map((place) => ({ place, distance: this.map.distance([lat, lng], [place.lat, place.lng]) }))
                                .sort((a, b) => a.distance - b.distance);
                            const match = ranked.find((row) => row.distance <= row.place.radius);
                            const pick = match ?? ranked[0];

                            return { withinRadius: Boolean(match), distance: pick.distance, place: pick.place };
                        },
                        updateStatus(result, accuracy = null) {
                            if (!this.statusTarget) {
                                return;
                            }

                            const accuracyText = accuracy !== null ? ` (Akurasi GPS: ${self.formatDistance(accuracy)})` : '';

                            if (!result.place) {
                                this.statusTarget.className = 'mt-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700';
                                this.statusTarget.textContent = 'Lokasi terbaca. Belum ada area absensi yang diatur.' + accuracyText;
                                return;
                            }

                            const distance = self.formatDistance(result.distance);

                            if (result.withinRadius) {
                                this.statusTarget.className = 'mt-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm font-medium text-green-700';
                                this.statusTarget.textContent = `Anda berada di area ${result.place.name} (${distance} dari titik).` + accuracyText;
                            } else {
                                this.statusTarget.className = 'mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700';
                                this.statusTarget.textContent = `Anda berada di luar area absensi. Terdekat: ${result.place.name}, ${distance}.` + accuracyText;
                            }
                        },
                        trackUserLocation(maxRetries = 2) {
                            if (!navigator.geolocation) {
                                if (this.statusTarget) {
                                    this.statusTarget.className = 'mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700';
                                    this.statusTarget.textContent = 'Peramban tidak mendukung geolokasi.';
                                }
                                return Promise.reject(new Error('Geolocation unsupported'));
                            }

                            let retryCount = 0;

                            const attemptLocation = (resolve, reject) => {
                                navigator.geolocation.getCurrentPosition(
                                    (position) => {
                                        const userLat = position.coords.latitude;
                                        const userLng = position.coords.longitude;
                                        const accuracy = position.coords.accuracy;

                                        // Retry jika accuracy terlalu buruk (>50 meter) dan masih ada retry
                                        if (accuracy > 50 && retryCount < maxRetries) {
                                            retryCount++;
                                            if (this.statusTarget) {
                                                this.statusTarget.className = 'mt-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-700';
                                                this.statusTarget.textContent = 'Akurasi GPS rendah, mencoba lagi... (' + retryCount + '/' + maxRetries + ')';
                                            }
                                            setTimeout(() => attemptLocation(resolve, reject), 1000);
                                            return;
                                        }

                                        if (this.userMarker) {
                                            this.userMarker.setLatLng([userLat, userLng]);
                                        } else {
                                            this.userMarker = L.marker([userLat, userLng])
                                                .addTo(this.map)
                                                .bindPopup('Lokasi Anda');
                                        }

                                        if (this.userAccuracyCircle) {
                                            this.userAccuracyCircle.setLatLng([userLat, userLng]);
                                            this.userAccuracyCircle.setRadius(accuracy);
                                        } else {
                                            this.userAccuracyCircle = L.circle([userLat, userLng], {
                                                color: '#1f969e',
                                                fillColor: '#3fb2bb',
                                                fillOpacity: 0.15,
                                                radius: accuracy,
                                                weight: 1,
                                            }).addTo(this.map);
                                        }

                                        this.map.panTo([userLat, userLng]);

                                        const result = this.locate(userLat, userLng);
                                        this.updateStatus(result, accuracy);

                                        resolve({
                                            latitude: userLat,
                                            longitude: userLng,
                                            accuracy,
                                            distance: result.distance,
                                            withinRadius: result.withinRadius,
                                            placeName: result.place?.name ?? null,
                                        });
                                    },
                                    (error) => {
                                        if (this.statusTarget) {
                                            this.statusTarget.className = 'mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-700';
                                            this.statusTarget.textContent = 'Gagal mengambil GPS. Izinkan akses lokasi lalu coba lagi.';
                                        }
                                        reject(error);
                                    },
                                    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
                                );
                            };

                            return new Promise((resolve, reject) => {
                                attemptLocation(resolve, reject);
                            });
                        },
                    };

                    this.instances[container.id] = instance;

                    if (editable && primary) {
                        primary.marker.on('dragend', () => {
                            const { lat, lng } = primary.marker.getLatLng();
                            instance.setOffice(lat, lng);

                            const latInput = document.getElementById('office_latitude');
                            const lngInput = document.getElementById('office_longitude');
                            if (latInput) {
                                latInput.value = lat.toFixed(8);
                                latInput.dispatchEvent(new Event('input', { bubbles: true }));
                            }
                            if (lngInput) {
                                lngInput.value = lng.toFixed(8);
                                lngInput.dispatchEvent(new Event('input', { bubbles: true }));
                            }
                        });
                    }

                    if (trackUser) {
                        instance.trackUserLocation().catch(() => {});
                    }

                    setTimeout(() => map.invalidateSize(), 150);
                },

                initAll() {
                    document.querySelectorAll('[data-geofence-map]').forEach((container) => {
                        this.init(container);
                    });
                },

                getInstance(mapId) {
                    return this.instances[mapId] ?? null;
                },

                bindSettingsRadius() {
                    const radiusInput = document.getElementById('attendance_radius_meters');
                    const mapContainer = document.querySelector('[data-geofence-map][data-editable="1"]');

                    if (!radiusInput || !mapContainer) {
                        return;
                    }

                    const instance = () => this.getInstance(mapContainer.id);

                    const updateRadius = () => {
                        const value = parseInt(radiusInput.value, 10);
                        if (value > 0) {
                            instance()?.setRadius(value);
                        }
                    };

                    const updatePoint = () => {
                        const lat = parseFloat(document.getElementById('office_latitude')?.value);
                        const lng = parseFloat(document.getElementById('office_longitude')?.value);
                        if (Number.isFinite(lat) && Number.isFinite(lng)) {
                            instance()?.setOffice(lat, lng);
                        }
                    };

                    radiusInput.addEventListener('input', updateRadius);
                    radiusInput.addEventListener('change', updateRadius);
                    document.getElementById('office_latitude')?.addEventListener('change', updatePoint);
                    document.getElementById('office_longitude')?.addEventListener('change', updatePoint);

                    document.querySelectorAll('[data-radius-preset]').forEach((button) => {
                        button.addEventListener('click', () => {
                            radiusInput.value = button.dataset.radiusPreset;
                            updateRadius();
                        });
                    });
                },

                bindAttendanceForms() {
                    document.querySelectorAll('[data-geofence-form]').forEach((form) => {
                        form.addEventListener('submit', async (event) => {
                            event.preventDefault();

                            const mapId = form.dataset.geofenceMapId;
                            const instance = mapId ? this.getInstance(mapId) : null;
                            const submitButton = form.querySelector('[type="submit"]');

                            if (submitButton) {
                                submitButton.disabled = true;
                            }

                            try {
                                if (!instance) {
                                    throw new Error('Map not found');
                                }

                                const result = await instance.trackUserLocation();

                                if (!result.withinRadius) {
                                    return;
                                }

                                form.querySelector('[name="latitude"]').value = result.latitude;
                                form.querySelector('[name="longitude"]').value = result.longitude;
                                form.submit();
                            } catch {
                                // Status message already shown on map.
                            } finally {
                                if (submitButton) {
                                    submitButton.disabled = false;
                                }
                            }
                        });
                    });
                },
            };

            function bootGeofenceMaps() {
                if (window.GeofenceMap.booted) {
                    return;
                }

                window.GeofenceMap.booted = true;
                window.GeofenceMap.initAll();
                window.GeofenceMap.bindSettingsRadius();
                window.GeofenceMap.bindAttendanceForms();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', bootGeofenceMaps);
            } else {
                bootGeofenceMaps();
            }
        </script>
    @endpush
@endonce

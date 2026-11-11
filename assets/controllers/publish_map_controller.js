import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';
import { decodePolyline } from '../lib/polyline.js';

const TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const ATTRIBUTION = '<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">© OpenStreetMap</a>';
const ROUTE_DELAY_MS = 600;
const ROW = '[data-stop-list-target="item"]';

/*
 * Map of the publish form. The driver clicks the map to place the meeting point of a stop
 * (or searches it by name), the route is drawn between the stops and the server suggests
 * a price and an arrival time per leg. Everything is optional: without JavaScript, or when
 * the map services are down, the form still works.
 */
export default class extends Controller {
    static targets = ['canvas', 'note', 'hint'];
    static values = { cities: Object };

    connect() {
        this.active = null;
        this.markers = new Map();
        this.routeSeq = 0;
        this.timer = null;
        this.abort = null;

        // Clear the placeholder text first: Leaflet builds its panes inside the element.
        this.canvasTarget.textContent = '';
        this.canvasTarget.classList.add('is-live');
        this.map = L.map(this.canvasTarget, { scrollWheelZoom: false }).setView([31.8, -7.1], 6);
        L.tileLayer(TILES, { maxZoom: 18, attribution: ATTRIBUTION }).addTo(this.map);
        this.map.invalidateSize();
        // The aside may be laid out after connect().
        this.frame = requestAnimationFrame(() => this.map?.invalidateSize());
        this.cityLayer = L.layerGroup().addTo(this.map);
        this.legLayer = L.layerGroup().addTo(this.map);

        this.map.on('click', (event) => this.place(event.latlng));

        this.onChange = (event) => this.handleChange(event);
        this.onFocus = (event) => this.handleFocus(event);
        this.onKeydown = (event) => this.handleKeydown(event);
        this.element.addEventListener('change', this.onChange);
        this.element.addEventListener('focusin', this.onFocus);
        this.element.addEventListener('keydown', this.onKeydown);

        // Rows added or removed by the stop-list controller.
        const list = this.element.querySelector('.stop-list');
        this.observer = new MutationObserver(() => this.rowsChanged());
        if (list) {
            this.observer.observe(list, { childList: true });
        }

        this.setActive(this.rows()[0] ?? null);
        this.syncMarkers();
        this.fit();
        this.scheduleRoute();
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
        clearTimeout(this.timer);
        this.abort?.abort();
        this.observer?.disconnect();
        this.element.removeEventListener('change', this.onChange);
        this.element.removeEventListener('focusin', this.onFocus);
        this.element.removeEventListener('keydown', this.onKeydown);
        this.map?.remove();
        this.map = null;
    }

    // ----- rows ---------------------------------------------------------------------------

    rows() {
        return Array.from(this.element.querySelectorAll(ROW));
    }

    field(row, name) {
        return row.querySelector(`[name$="[${name}]"]`);
    }

    cityPoint(row) {
        const id = this.field(row, 'city')?.value;
        return id && this.citiesValue[id] ? this.citiesValue[id] : null;
    }

    meetingPoint(row) {
        const lat = parseFloat(this.field(row, 'meetingLatitude')?.value);
        const lng = parseFloat(this.field(row, 'meetingLongitude')?.value);
        return Number.isFinite(lat) && Number.isFinite(lng) ? [lat, lng] : null;
    }

    cityName(row) {
        const select = this.field(row, 'city');
        return select && select.value ? select.options[select.selectedIndex].text.trim() : '';
    }

    setActive(row) {
        this.active = row;
        this.rows().forEach((r) => r.classList.toggle('is-active', r === row));
        this.markers.forEach((marker, r) => marker.getElement()?.classList.toggle('is-active', r === row));
    }

    // ----- events -------------------------------------------------------------------------

    handleFocus(event) {
        const row = event.target.closest?.(ROW);
        if (row && event.target.matches('[name$="[meetingPoint]"]')) {
            this.setActive(row);
        }
    }

    handleKeydown(event) {
        // Enter in the meeting point field searches the place instead of submitting the form.
        if (event.key === 'Enter' && event.target.matches?.('[name$="[meetingPoint]"]')) {
            event.preventDefault();
            this.searchRow(event.target.closest(ROW));
        }
    }

    handleChange(event) {
        const target = event.target;
        if (target.matches('[name$="[legPriceToNext]"]')) {
            this.refreshPriceHint(target.closest(ROW));
            return;
        }
        if (target.matches('[name$="[city]"]')) {
            this.fit();
        }
        this.syncMarkers();
        this.scheduleRoute();
    }

    rowsChanged() {
        if (this.active && !this.rows().includes(this.active)) {
            this.setActive(this.rows()[0] ?? null);
        }
        this.syncMarkers();
        this.fit();
        this.scheduleRoute();
    }

    // ----- markers ------------------------------------------------------------------------

    syncMarkers() {
        const rows = this.rows();
        if (this.hasHintTarget) {
            this.hintTarget.hidden = rows.some((row) => this.meetingPoint(row));
        }
        this.markers.forEach((marker, row) => {
            if (!rows.includes(row) || !this.meetingPoint(row)) {
                marker.remove();
                this.markers.delete(row);
            }
        });
        rows.forEach((row, i) => {
            const point = this.meetingPoint(row);
            if (!point) {
                return;
            }
            let marker = this.markers.get(row);
            if (!marker) {
                marker = L.marker(point, { draggable: true, keyboard: false, icon: this.pinIcon(i + 1) }).addTo(this.map);
                marker.on('dragend', () => this.moved(row, marker.getLatLng()));
                marker.on('click', () => this.setActive(row));
                this.markers.set(row, marker);
            } else {
                marker.setLatLng(point);
                marker.setIcon(this.pinIcon(i + 1));
            }
            marker.getElement()?.classList.toggle('is-active', row === this.active);
        });

        this.cityLayer.clearLayers();
        rows.forEach((row) => {
            const city = this.cityPoint(row);
            if (city) {
                L.circleMarker(city, { radius: 5, weight: 2, color: this.color('--dusk', '#22305C'), fillColor: '#fff', fillOpacity: 1, interactive: false }).addTo(this.cityLayer);
            }
        });
    }

    pinIcon(number) {
        return L.divIcon({ className: 'map-pin map-pin--numbered', html: `<span>${number}</span>`, iconSize: [28, 28], iconAnchor: [14, 14] });
    }

    color(name, fallback) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    }

    fit() {
        const points = [];
        this.rows().forEach((row) => {
            const point = this.meetingPoint(row) ?? this.cityPoint(row);
            if (point) {
                points.push(point);
            }
        });
        if (points.length === 1) {
            this.map.setView(points[0], 11);
        } else if (points.length > 1) {
            this.map.fitBounds(L.latLngBounds(points), { padding: [32, 32], maxZoom: 13 });
        }
    }

    // ----- placing a meeting point ------------------------------------------------------

    place(latlng) {
        const row = this.active ?? this.rows()[0];
        if (!row) {
            return;
        }
        this.setPoint(row, latlng.lat, latlng.lng);
        this.reverse(row, latlng.lat, latlng.lng);
    }

    moved(row, latlng) {
        this.setPoint(row, latlng.lat, latlng.lng);
        this.reverse(row, latlng.lat, latlng.lng);
    }

    setPoint(row, lat, lng) {
        this.field(row, 'meetingLatitude').value = lat.toFixed(6);
        this.field(row, 'meetingLongitude').value = lng.toFixed(6);
        this.syncMarkers();
        this.scheduleRoute();
    }

    async reverse(row, lat, lng) {
        const input = this.field(row, 'meetingPoint');
        if (!input || input.value.trim() !== '') {
            return;
        }
        try {
            const response = await fetch(`/api/reverse?lat=${lat}&lng=${lng}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const { place } = await response.json();
            // The driver may have typed something while we were waiting.
            if (place?.label && input.value.trim() === '' && this.rows().includes(row)) {
                input.value = place.label;
            }
        } catch (error) {
            // No label: the driver types it.
        }
    }

    search(event) {
        this.searchRow(event.currentTarget.closest(ROW));
    }

    async searchRow(row) {
        if (!row) {
            return;
        }
        this.setActive(row);
        const status = row.querySelector('[data-role="meeting-status"]');
        const text = this.field(row, 'meetingPoint').value.trim();
        const query = [text, this.cityName(row)].filter(Boolean).join(' ');
        if (query.length < 3) {
            this.say(status, 'Indiquez un lieu ou une ville.');
            return;
        }
        this.say(status, 'Recherche…');
        try {
            const response = await fetch(`/api/geocode?q=${encodeURIComponent(query)}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            const { places } = await response.json();
            if (!places || places.length === 0) {
                this.say(status, 'Lieu introuvable. Cliquez sur la carte pour le placer.');
                return;
            }
            this.say(status, '');
            this.setPoint(row, places[0].lat, places[0].lng);
            this.map.setView([places[0].lat, places[0].lng], 15);
        } catch (error) {
            this.say(status, 'Recherche indisponible. Cliquez sur la carte pour placer le point.');
        }
    }

    say(element, text) {
        if (element) {
            element.textContent = text;
        }
    }

    // ----- route and suggestions --------------------------------------------------------

    scheduleRoute() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.loadRoute(), ROUTE_DELAY_MS);
    }

    routePoints() {
        const used = [];
        this.rows().forEach((row, index) => {
            const point = this.meetingPoint(row) ?? this.cityPoint(row);
            if (point) {
                used.push({ row, index, point });
            }
        });
        return used;
    }

    async loadRoute() {
        const used = this.routePoints();
        this.clearSuggestions();
        this.legLayer.clearLayers();
        if (used.length < 2) {
            this.noteTarget.hidden = true;
            return;
        }

        const seq = ++this.routeSeq;
        this.abort?.abort();
        this.abort = new AbortController();
        const points = used.map((u) => `${u.point[0]},${u.point[1]}`).join(';');
        const departures = used.slice(0, -1).map((u) => this.field(u.row, 'departureAt')?.value ?? '').join(',');

        try {
            const response = await fetch(`/api/route?points=${encodeURIComponent(points)}&departures=${encodeURIComponent(departures)}`, {
                headers: { Accept: 'application/json' },
                signal: this.abort.signal,
            });
            if (seq !== this.routeSeq) {
                return;
            }
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            const { legs } = await response.json();
            if (!Array.isArray(legs) || legs.length !== used.length - 1) {
                throw new Error('Unexpected route');
            }
            if (seq !== this.routeSeq) {
                return;
            }
            this.noteTarget.hidden = true;
            this.drawRoute(used, legs);
            this.showSuggestions(used, legs);
        } catch (error) {
            if (error.name === 'AbortError' || seq !== this.routeSeq) {
                return;
            }
            this.noteTarget.hidden = false;
            this.drawRoute(used, null);
        }
    }

    drawRoute(used, legs) {
        this.legLayer.clearLayers();
        const style = { color: this.color('--dusk', '#22305C'), weight: 4, opacity: 0.85, lineCap: 'round', lineJoin: 'round' };
        for (let i = 0; i < used.length - 1; i++) {
            let line = legs ? decodePolyline(legs[i].geometry) : [];
            let options = style;
            if (line.length < 2) {
                line = [used[i].point, used[i + 1].point];
                options = { ...style, dashArray: '6 8' };
            }
            L.polyline(line, options).addTo(this.legLayer);
        }
    }

    clearSuggestions() {
        this.rows().forEach((row) => {
            ['price-suggestion', 'time-suggestion'].forEach((role) => {
                const el = row.querySelector(`[data-role="${role}"]`);
                if (el) {
                    el.hidden = true;
                    el.replaceChildren();
                }
            });
        });
        this.suggestedPrices = new Map();
    }

    showSuggestions(used, legs) {
        legs.forEach((leg, i) => {
            const from = used[i];
            const to = used[i + 1];
            // A suggestion only makes sense for a leg between two consecutive stops.
            if (to.index !== from.index + 1) {
                return;
            }
            if (Number.isFinite(leg.suggestedPrice)) {
                this.suggestedPrices.set(from.row, leg.suggestedPrice);
                this.refreshPriceHint(from.row);
            }
            if (typeof leg.suggestedNextDeparture === 'string' && leg.suggestedNextDeparture.length >= 16) {
                this.offer(to.row, 'time-suggestion', `Heure conseillée : ${leg.suggestedNextDeparture.slice(11, 16)}`, () => {
                    this.fill(this.field(to.row, 'departureAt'), leg.suggestedNextDeparture);
                });
            }
        });
    }

    refreshPriceHint(row) {
        const price = this.suggestedPrices?.get(row);
        const input = this.field(row, 'legPriceToNext');
        const el = row.querySelector('[data-role="price-suggestion"]');
        if (!el || price === undefined || !input) {
            return;
        }
        if (input.value !== '' && Number(input.value) === price) {
            el.hidden = true;
            return;
        }
        this.offer(row, 'price-suggestion', `Prix conseillé : ${price} MAD`, () => this.fill(input, String(price)));
    }

    offer(row, role, text, apply) {
        const el = row.querySelector(`[data-role="${role}"]`);
        if (!el) {
            return;
        }
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'suggest-use';
        button.textContent = 'Utiliser';
        button.addEventListener('click', apply);
        el.replaceChildren(`${text} `, button);
        el.hidden = false;
    }

    fill(input, value) {
        if (!input) {
            return;
        }
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';
import { decodePolyline } from '../lib/polyline.js';

const TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const ATTRIBUTION = '<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">© OpenStreetMap</a>';
const ROUTE_DELAY_MS = 600;
const ROW = '[data-stop-list-target="item"]';

/*
 * Route preview of the publish form: the route is drawn between the stops (placed meeting points
 * shown as small dots) and the server suggests a price and an arrival time per leg. Meeting points
 * are chosen in the picker (meeting_picker_controller.js). Everything is optional: without
 * JavaScript, or when the map services are down, the form still works.
 */
export default class extends Controller {
    static targets = ['canvas', 'note', 'status'];
    static values = { cities: Object };

    connect() {
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

        this.onChange = (event) => this.handleChange(event);
        this.element.addEventListener('change', this.onChange);

        // Rows added or removed by the stop-list controller.
        const list = this.element.querySelector('.stop-list');
        this.observer = new MutationObserver(() => this.rowsChanged());
        if (list) {
            this.observer.observe(list, { childList: true });
        }

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
        this.syncMarkers();
        this.fit();
        this.scheduleRoute();
    }

    // ----- markers ------------------------------------------------------------------------

    syncMarkers() {
        this.cityLayer.clearLayers();
        this.rows().forEach((row) => {
            const city = this.cityPoint(row);
            if (city) {
                L.circleMarker(city, { radius: 5, weight: 2, color: this.color('--dusk', '#22305C'), fillColor: '#fff', fillOpacity: 1, interactive: false }).addTo(this.cityLayer);
            }
            const meeting = this.meetingPoint(row);
            if (meeting) {
                L.circleMarker(meeting, { radius: 4, weight: 2, color: '#fff', fillColor: this.color('--dusk', '#22305C'), fillOpacity: 1, interactive: false }).addTo(this.cityLayer);
            }
        });
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
            this.setStatus('');
            return;
        }

        const seq = ++this.routeSeq;
        this.setStatus('Calcul de l\'itinéraire…');
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
            this.setStatus('Distances calculées : prix conseillés proposés.');
            this.drawRoute(used, legs);
            this.showSuggestions(used, legs);
        } catch (error) {
            if (error.name === 'AbortError' || seq !== this.routeSeq) {
                return;
            }
            this.noteTarget.hidden = false;
            this.setStatus('Distance indisponible : fixez le prix de chaque tronçon vous-même.');
            this.priceInputs().forEach((input) => { input.placeholder = 'Votre prix (MAD)'; });
            this.drawRoute(used, null);
        }
    }

    setStatus(text) {
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = text;
        }
    }

    priceInputs() {
        return this.rows().map((row) => this.field(row, 'legPriceToNext')).filter(Boolean);
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
                const suggestion = leg.suggestedNextDeparture;
                const input = this.field(to.row, 'departureAt');
                const hhmm = suggestion.slice(11, 16);
                if (input && (input.value === '' || input.dataset.autofilled === '1')) {
                    // Empty, or still the value Rihla filled in: follow the new calculation.
                    this.fill(input, suggestion);
                    input.dataset.autofilled = '1'; // after fill(): its synthetic events clear the flag
                    if (!input.dataset.autofillWatched) {
                        input.dataset.autofillWatched = '1';
                        const forget = () => { delete input.dataset.autofilled; };
                        input.addEventListener('input', forget);
                        input.addEventListener('change', forget);
                    }
                    const el = to.row.querySelector('[data-role="time-suggestion"]');
                    if (el) {
                        el.replaceChildren(`Heure calculée par Rihla : ${hhmm}, modifiable`);
                        el.hidden = false;
                    }
                } else {
                    this.offer(to.row, 'time-suggestion', `Heure conseillée : ${hhmm}`, () => {
                        this.fill(input, suggestion);
                    });
                }
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

import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';

const TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const ATTRIBUTION = '<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">© OpenStreetMap</a>';
const ROW = '[data-stop-list-target="item"]';
const SETTLE_MS = 400;
const START_ZOOM = 15;
const FALLBACK_CENTRE = [31.8, -7.1];
const FOCUSABLE = 'button:not([disabled]), input:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';

/*
 * Meeting-point picker: one dialog for the whole publish page. The driver moves a big map under
 * a fixed pin; after each move the server names the place and says how far it is from the city.
 * A place too far from the city cannot be validated. The row keeps working without JavaScript:
 * the dialog and the summary only appear once this controller is connected.
 */
export default class extends Controller {
    static targets = [
        'backdrop', 'dialog', 'eyebrow', 'title', 'searchLabel', 'search', 'note', 'results',
        'popular', 'popularLabel', 'popularList', 'stage', 'canvas', 'address', 'far', 'farText',
        'detail', 'validate',
    ];
    static values = { maxKm: Number, cities: Object };

    connect() {
        this.row = null;
        this.opener = null;
        this.map = null;
        this.current = null;
        this.chosen = null;
        this.seq = 0;
        this.timer = null;
        this.abort = null;

        this.element.classList.add('has-picker');
        this.onKeydown = (event) => this.handleKeydown(event);
        this.onChange = () => this.syncAll();
        this.element.addEventListener('keydown', this.onKeydown);
        this.element.addEventListener('change', this.onChange);
        this.element.addEventListener('input', this.onChange);

        const list = this.element.querySelector('.stop-list');
        this.observer = new MutationObserver(() => this.syncAll());
        if (list) {
            this.observer.observe(list, { childList: true });
        }
        this.syncAll();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.abort?.abort();
        this.observer?.disconnect();
        this.element.removeEventListener('keydown', this.onKeydown);
        this.element.removeEventListener('change', this.onChange);
        this.element.removeEventListener('input', this.onChange);
        this.element.classList.remove('has-picker');
        document.body.classList.remove('picker-open');
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

    cityId(row) {
        return this.field(row, 'city')?.value || '';
    }

    cityName(row) {
        const select = this.field(row, 'city');
        return select && select.value ? select.options[select.selectedIndex].text.trim() : '';
    }

    cityPoint(row) {
        const point = this.citiesValue[this.cityId(row)];
        return point ? L.latLng(point[0], point[1]) : null;
    }

    meetingPoint(row) {
        const lat = parseFloat(this.field(row, 'meetingLatitude')?.value);
        const lng = parseFloat(this.field(row, 'meetingLongitude')?.value);
        return Number.isFinite(lat) && Number.isFinite(lng) ? L.latLng(lat, lng) : null;
    }

    /** Refreshes the summary of every row (pin + label, or "to choose") and the buttons. */
    syncAll() {
        this.rows().forEach((row) => this.syncRow(row));
    }

    syncRow(row) {
        const text = (this.field(row, 'meetingPoint')?.value ?? '').trim();
        const placed = this.meetingPoint(row) !== null;
        const hasCity = this.cityId(row) !== '';
        const set = (role) => row.querySelector(`[data-role="${role}"]`);

        set('meeting-placed').hidden = !placed;
        set('meeting-empty').hidden = placed;
        set('meeting-label').textContent = text || 'Point placé sur la carte';
        set('meeting-text').textContent = text || 'Point de rendez-vous à choisir';
        set('meeting-text').classList.toggle('is-typed', text !== '');
        const choose = set('meeting-choose');
        choose.disabled = !hasCity;
        set('meeting-hint').hidden = hasCity;
        const edit = set('meeting-edit');
        edit.disabled = !hasCity;

        const label = row.querySelector('.stop-meeting label');
        if (label) {
            label.textContent = 'Précision (facultatif)';
        }
    }

    clear(event) {
        const row = event.currentTarget.closest(ROW);
        ['meetingLatitude', 'meetingLongitude'].forEach((name) => this.fill(this.field(row, name), ''));
        this.syncRow(row);
        row.querySelector('[data-role="meeting-choose"]')?.focus();
    }

    fill(input, value) {
        if (!input) {
            return;
        }
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // ----- open / close -------------------------------------------------------------------

    open(event) {
        const row = event.currentTarget.closest(ROW);
        const city = this.cityPoint(row);
        if (!row || this.cityId(row) === '') {
            return;
        }
        this.row = row;
        this.opener = event.currentTarget;
        this.current = null;
        this.chosen = null;

        const name = this.cityName(row);
        const role = row.querySelector('[data-stop-list-target="label"]')?.textContent.trim() ?? 'Arrêt';
        const time = (this.field(row, 'departureAt')?.value ?? '').slice(11, 16);
        this.eyebrowTarget.textContent = time ? `${role} · ${time}` : role;
        this.titleTarget.textContent = `Rendez-vous à ${name}`;
        this.searchLabelTarget.textContent = `Chercher un lieu à ${name}`;
        this.searchTarget.setAttribute('aria-label', `Chercher un lieu à ${name}`);
        this.searchTarget.value = '';
        this.popularLabelTarget.textContent = `Lieux souvent choisis à ${name}`;
        this.clearResults();
        this.popularTarget.hidden = true;
        this.popularListTarget.replaceChildren();
        this.render({ pending: true });

        this.backdropTarget.hidden = false;
        document.body.classList.add('picker-open');
        this.ensureMap();
        this.map.invalidateSize();
        this.zone.clearLayers();
        if (city && this.maxKmValue > 0) {
            L.circle(city, { radius: this.maxKmValue * 1000, color: this.color('--dusk', '#22305C'), weight: 2, opacity: 0.45, dashArray: '8 8', fill: false, interactive: false }).addTo(this.zone);
        }
        const existing = this.meetingPoint(row);
        this.map.setView(existing ?? city ?? FALLBACK_CENTRE, existing || city ? START_ZOOM : 6, { animate: false });
        this.showOtherPoints(row);
        this.scheduleReverse(0);
        this.loadPopular(row);
        this.searchTarget.focus();
    }

    cancel() {
        this.close();
    }

    close() {
        clearTimeout(this.timer);
        this.abort?.abort();
        this.seq++;
        this.backdropTarget.hidden = true;
        document.body.classList.remove('picker-open');
        const opener = this.opener;
        const row = this.row;
        this.row = null;
        this.opener = null;
        // The summary may have switched buttons: focus the one that is shown in that row.
        const target = row ? Array.from(row.querySelectorAll('.meeting-open')).find((b) => !b.closest('[hidden]') && !b.disabled) : null;
        (target ?? opener)?.focus();
    }

    validate() {
        const row = this.row;
        const place = this.current;
        if (!row || !place || place.tooFar || place.pending) {
            return;
        }
        this.fill(this.field(row, 'meetingLatitude'), place.lat.toFixed(6));
        this.fill(this.field(row, 'meetingLongitude'), place.lng.toFixed(6));
        if (place.label) {
            this.fill(this.field(row, 'meetingPoint'), place.label);
        }
        this.syncRow(row);
        this.close();
    }

    handleKeydown(event) {
        if (this.backdropTarget.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.close();
        } else if (event.key === 'Tab') {
            this.trapFocus(event);
        }
    }

    trapFocus(event) {
        const items = Array.from(this.dialogTarget.querySelectorAll(FOCUSABLE)).filter((el) => !el.closest('[hidden]') && el.offsetParent !== null);
        if (items.length === 0) {
            return;
        }
        const first = items[0];
        const last = items[items.length - 1];
        if (!this.dialogTarget.contains(document.activeElement)) {
            event.preventDefault();
            first.focus();
        } else if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    // ----- map ----------------------------------------------------------------------------

    ensureMap() {
        if (this.map) {
            return;
        }
        this.map = L.map(this.canvasTarget, { zoomControl: false, attributionControl: false, minZoom: 5, maxZoom: 18 });
        L.tileLayer(TILES, { maxZoom: 18 }).addTo(this.map);
        L.control.zoom({ position: 'topright', zoomInTitle: 'Zoomer', zoomOutTitle: 'Dézoomer' }).addTo(this.map);
        L.control.attribution({ position: 'topleft', prefix: false }).addAttribution(ATTRIBUTION).addTo(this.map);
        this.zone = L.layerGroup().addTo(this.map);
        this.others = L.layerGroup().addTo(this.map);
        this.map.on('moveend', () => this.moved());
    }

    /** Small dots for the meeting points already chosen on the other stops. */
    showOtherPoints(row) {
        this.others.clearLayers();
        this.rows().forEach((other) => {
            const point = this.meetingPoint(other);
            if (other !== row && point) {
                L.circleMarker(point, { radius: 5, weight: 2, color: '#fff', fillColor: this.color('--dusk', '#22305C'), fillOpacity: 0.6, interactive: false }).addTo(this.others);
            }
        });
    }

    color(name, fallback) {
        return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
    }

    moved() {
        if (this.backdropTarget.hidden) {
            return;
        }
        // A place picked in the list sits exactly under the pin: keep its own name.
        if (this.chosen && this.map.getCenter().distanceTo(this.chosen) < 3) {
            return;
        }
        this.chosen = null;
        this.render({ pending: true });
        this.scheduleReverse(SETTLE_MS);
    }

    scheduleReverse(delay) {
        clearTimeout(this.timer);
        const seq = ++this.seq;
        this.timer = setTimeout(() => this.reverse(seq), delay);
    }

    async reverse(seq) {
        const row = this.row;
        if (!row) {
            return;
        }
        const centre = this.map.getCenter();
        this.abort?.abort();
        this.abort = new AbortController();
        let place = null;
        try {
            const url = `/api/reverse?lat=${centre.lat.toFixed(6)}&lng=${centre.lng.toFixed(6)}&city=${encodeURIComponent(this.cityId(row))}`;
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: this.abort.signal });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            place = (await response.json()).place;
        } catch (error) {
            if (error.name === 'AbortError' || seq !== this.seq) {
                return;
            }
        }
        if (seq !== this.seq || this.row !== row) {
            return;
        }
        this.render(this.describe(row, centre, place?.label ?? null, place?.distanceKm, place?.tooFar));
    }

    /** The server answer wins; without it the distance is computed here. */
    describe(row, latlng, label, distanceKm, tooFar) {
        const city = this.cityPoint(row);
        if (distanceKm === undefined && city) {
            distanceKm = Math.round((latlng.distanceTo(city) / 1000) * 10) / 10;
            tooFar = distanceKm > this.maxKmValue;
        }
        return { label, lat: latlng.lat, lng: latlng.lng, distanceKm, tooFar: Boolean(tooFar) };
    }

    // ----- status card --------------------------------------------------------------------

    render(state) {
        const name = this.row ? this.cityName(this.row) : '';
        this.current = state.pending ? null : state;
        const far = Boolean(state.tooFar);
        this.stageTarget.classList.toggle('is-far', far);
        this.farTarget.hidden = !far;
        this.detailTarget.hidden = far || state.pending;
        this.validateTarget.disabled = state.pending === true || far;

        if (state.pending) {
            this.addressTarget.textContent = 'Recherche de l\'adresse…';
            this.detailTarget.textContent = '';
            return;
        }
        this.addressTarget.textContent = state.label ?? 'Adresse inconnue, vous pourrez la préciser';
        const km = this.formatKm(state.distanceKm);
        if (far) {
            this.farTextTarget.textContent = `Ce lieu est trop loin de ${name} (${km} km). Choisissez un endroit dans la ville.`;
        } else {
            this.detailTarget.textContent = state.distanceKm === undefined ? '' : `${km} km du centre de ${name}`;
        }
    }

    formatKm(km) {
        return km === undefined ? '' : String(Math.round(km)) === String(km) ? String(km) : String(km).replace('.', ',');
    }

    // ----- search and suggestions ---------------------------------------------------------

    searchKey(event) {
        // Enter searches; it must never submit the publish form.
        if (event.key === 'Enter') {
            event.preventDefault();
            this.search();
        }
    }

    clearResults() {
        this.resultsTarget.replaceChildren();
        this.resultsTarget.hidden = true;
        this.say('');
    }

    say(text) {
        this.noteTarget.textContent = text;
        this.noteTarget.hidden = text === '';
    }

    async search() {
        const row = this.row;
        const query = this.searchTarget.value.trim();
        if (!row) {
            return;
        }
        this.clearResults();
        if (query.length < 3) {
            this.say('Saisissez au moins 3 lettres.');
            return;
        }
        this.say('Recherche…');
        try {
            const response = await fetch(`/api/geocode?q=${encodeURIComponent(query)}&city=${encodeURIComponent(this.cityId(row))}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error(String(response.status));
            }
            const { places } = await response.json();
            if (this.row !== row) {
                return;
            }
            if (!places || places.length === 0) {
                this.say('Aucun lieu trouvé. Essayez un autre nom ou déplacez la carte.');
                return;
            }
            this.say('');
            this.resultsTarget.hidden = false;
            places.forEach((place) => {
                const detail = place.tooFar ? `Trop loin : ${this.formatKm(place.distanceKm)} km du centre` : `${this.formatKm(place.distanceKm)} km du centre`;
                this.resultsTarget.append(this.choice(place.label, place.distanceKm === undefined ? '' : detail, () => this.choose(place)));
            });
        } catch (error) {
            this.say('Recherche indisponible. Déplacez la carte pour placer le point.');
        }
    }

    async loadPopular(row) {
        try {
            const response = await fetch(`/api/meeting-points?city=${encodeURIComponent(this.cityId(row))}`, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const { places } = await response.json();
            if (this.row !== row || !Array.isArray(places) || places.length === 0) {
                return;
            }
            this.popularListTarget.replaceChildren(...places.map((place) => this.choice(
                place.label,
                place.uses > 1 ? `Choisi ${place.uses} fois` : 'Déjà choisi par un conducteur',
                () => this.choose(place),
            )));
            this.popularTarget.hidden = false;
        } catch (error) {
            // The block simply stays hidden.
        }
    }

    choice(label, detail, onPick) {
        const item = document.createElement('li');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'picker-choice';
        const name = document.createElement('span');
        name.className = 'picker-choice-name';
        name.textContent = label;
        button.append(name);
        if (detail) {
            const small = document.createElement('span');
            small.className = 'picker-choice-detail';
            small.textContent = detail;
            button.append(small);
        }
        button.addEventListener('click', onPick);
        item.append(button);
        return item;
    }

    /** Moves the map under the pin; the driver still has to press « Valider ce point ». */
    choose(place) {
        const row = this.row;
        if (!row) {
            return;
        }
        const latlng = L.latLng(place.lat, place.lng);
        this.chosen = latlng;
        clearTimeout(this.timer);
        this.abort?.abort();
        this.seq++;
        this.render(this.describe(row, latlng, place.label, place.distanceKm, place.tooFar));
        this.map.setView(latlng, 16, { animate: false });
    }
}

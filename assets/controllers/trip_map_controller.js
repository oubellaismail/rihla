import { Controller } from '@hotwired/stimulus';
import L from 'leaflet';
import 'leaflet/dist/leaflet.min.css';
import { decodePolyline } from '../lib/polyline.js';

const TILES = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const ATTRIBUTION = '<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">© OpenStreetMap</a>';

/*
 * Map of a trip (trip page, booking page, driver page). The data comes from TripMapBuilder:
 * the server already decided which exact meeting points this person may see.
 */
export default class extends Controller {
    static values = { data: Object };

    connect() {
        const data = this.dataValue;
        const stops = (data.stops || []).filter((s) => this.hasPoint(s.lat, s.lng));
        if (stops.length === 0) {
            this.element.textContent = 'Carte indisponible pour ce trajet.';
            return;
        }

        this.colors = this.readColors();
        this.element.textContent = '';
        this.element.classList.add('is-live');
        const canvas = document.createElement('div');
        canvas.className = 'map-canvas';
        this.element.append(canvas);

        this.map = L.map(canvas, { scrollWheelZoom: false });
        L.tileLayer(TILES, { maxZoom: 18, attribution: ATTRIBUTION }).addTo(this.map);

        const bounds = [];
        this.drawLegs(data, bounds);
        this.drawStops(data, bounds);
        if (data.viewer === 'driver') {
            this.drawLegend();
        }

        this.map.fitBounds(L.latLngBounds(bounds), { padding: [32, 32], maxZoom: 13 });
    }

    disconnect() {
        this.map?.remove();
        this.map = null;
        this.legend?.remove();
        this.legend = null;
        this.element.classList.remove('is-live');
    }

    hasPoint(lat, lng) {
        return typeof lat === 'number' && typeof lng === 'number';
    }

    readColors() {
        const css = getComputedStyle(document.documentElement);
        const read = (name, fallback) => css.getPropertyValue(name).trim() || fallback;
        return {
            dusk: read('--dusk', '#22305C'),
            sunset: read('--sunset', '#E8743B'),
            muted: read('--muted', '#5A5148'),
            saffron: read('--saffron', '#F2B544'),
            free: read('--map-free', '#2f855a'),
            full: read('--map-full', '#c0392b'),
            white: read('--white', '#ffffff'),
        };
    }

    stopAt(position) {
        return (this.dataValue.stops || []).find((s) => s.position === position);
    }

    drawLegs(data, bounds) {
        const highlight = data.highlight;
        (data.legs || []).forEach((leg) => {
            let style;
            if (data.viewer === 'driver') {
                style = { color: this.seatColor(leg.seatsTaken, data.seatsOffered), weight: 5, opacity: 0.9 };
            } else if (highlight) {
                const inside = leg.from >= highlight.from && leg.to <= highlight.to;
                style = inside
                    ? { color: this.colors.sunset, weight: 5, opacity: 1 }
                    : { color: this.colors.dusk, weight: 4, opacity: 0.6 };
            } else {
                style = { color: this.colors.dusk, weight: 4, opacity: 0.9 };
            }

            let line = decodePolyline(leg.geometry);
            if (line.length < 2) {
                const a = this.stopAt(leg.from);
                const b = this.stopAt(leg.to);
                if (!a || !b || !this.hasPoint(a.lat, a.lng) || !this.hasPoint(b.lat, b.lng)) {
                    return;
                }
                line = [[a.lat, a.lng], [b.lat, b.lng]];
                style = { ...style, dashArray: '6 8' };
            }
            L.polyline(line, { ...style, lineCap: 'round', lineJoin: 'round' }).addTo(this.map);
            bounds.push(...line);
        });
    }

    seatColor(taken, offered) {
        const free = (offered ?? 0) - (taken ?? 0);
        if (free <= 0) {
            return this.colors.full;
        }
        return free === 1 ? this.colors.saffron : this.colors.free;
    }

    drawStops(data, bounds) {
        (data.stops || []).forEach((stop) => {
            if (this.hasPoint(stop.lat, stop.lng)) {
                bounds.push([stop.lat, stop.lng]);
                L.circleMarker([stop.lat, stop.lng], {
                    radius: 6,
                    weight: 3,
                    color: this.colors.dusk,
                    fillColor: this.colors.white,
                    fillOpacity: 1,
                }).bindTooltip(this.tooltipText(stop), { direction: 'top', offset: [0, -6] }).addTo(this.map);
            }
            if (stop.exact && this.hasPoint(stop.exact.lat, stop.exact.lng)) {
                bounds.push([stop.exact.lat, stop.exact.lng]);
                const popup = document.createElement('div');
                popup.textContent = `Rendez-vous : ${stop.meetingPoint || stop.city}`;
                L.marker([stop.exact.lat, stop.exact.lng], {
                    icon: L.divIcon({ className: 'map-pin', html: '<span></span>', iconSize: [28, 28], iconAnchor: [14, 14], popupAnchor: [0, -14] }),
                    keyboard: false,
                }).bindPopup(popup).addTo(this.map);
            }
        });
    }

    tooltipText(stop) {
        return stop.time ? `${stop.city} · ${stop.time}` : stop.city;
    }

    drawLegend() {
        const legend = document.createElement('ul');
        legend.className = 'map-legend';
        [['free', 'Places libres'], ['warn', 'Dernière place'], ['full', 'Complet']].forEach(([kind, label]) => {
            const item = document.createElement('li');
            item.innerHTML = `<span class="map-legend-dot map-legend-dot--${kind}"></span>`;
            item.append(label);
            legend.append(item);
        });
        this.element.after(legend);
        this.legend = legend;
    }
}

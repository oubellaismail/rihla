import { Controller } from '@hotwired/stimulus';

/*
 * Seat plan: the checkboxes already work on their own (zero ticked + « Réserver » lets Rihla
 * pick). This controller keeps at most `count` seats ticked (a new tick drops the oldest),
 * writes the summary in the side panel and only enables the main button once every seat is chosen.
 */
export default class extends Controller {
    static targets = ['seat', 'title', 'detail', 'submit', 'hint', 'hintBox'];
    static values = { count: Number };

    connect() {
        this.order = [];
        this.initialHint = this.hintTarget.textContent;
        this.initialTitle = this.titleTarget.textContent;
        this.initialDetail = this.detailTarget.textContent;
        this.seatTargets.forEach((input) => { if (input.checked) this.order.push(input); });
        this.render();
    }

    toggle(event) {
        const input = event.target;
        if (input.checked) {
            this.order.push(input);
            while (this.order.length > this.countValue) {
                this.order.shift().checked = false;
            }
        } else {
            this.order = this.order.filter((other) => other !== input);
        }
        this.render();
    }

    /*
     * « Pas de préférence »: with JavaScript, tick the best free seats (same comfort order as the
     * server's SeatMap::autoPick) and bring the bags into view instead of booking at once.
     */
    autoPick(event) {
        event.preventDefault();
        const free = this.seatTargets.filter((input) => !input.disabled);
        const comfort = ['A1', 'B1', 'B3', 'B2', 'C1', 'C2'];
        const rank = (input) => {
            const i = comfort.indexOf(input.value);
            return i === -1 ? comfort.length : i;
        };
        free.sort((a, b) => rank(a) - rank(b));

        let picked = free.slice(0, this.countValue);
        if (this.countValue > 1) {
            // Side by side when a row has room for everyone (rows B, C, then A, like the server).
            for (const row of ['B', 'C', 'A']) {
                const inRow = free.filter((input) => input.value.startsWith(row));
                if (inRow.length >= this.countValue) {
                    picked = inRow.slice(0, this.countValue);
                    break;
                }
            }
        }

        this.seatTargets.forEach((input) => { input.checked = false; });
        picked.forEach((input) => { input.checked = true; });
        this.order = picked;
        this.render();

        const bags = this.element.querySelector('.luggage');
        if (bags) {
            const calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            bags.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' });
        }
    }

    render() {
        const picked = this.order;
        const codes = picked.map((input) => input.value);
        const left = this.countValue - picked.length;
        const list = this.list(codes);

        this.element.querySelectorAll('[data-seat]').forEach((seat) => seat.classList.toggle('is-picked', seat.querySelector('input').checked));

        if (picked.length === 0) {
            this.titleTarget.textContent = this.initialTitle;
            this.detailTarget.textContent = this.initialDetail;
            this.hintTarget.textContent = this.initialHint;
            this.hintBoxTarget.classList.remove('is-active');
        } else {
            this.titleTarget.textContent = `${picked.length > 1 ? 'Places' : 'Place'} ${list}`;
            this.detailTarget.textContent = picked.map((input) => `${input.value} : ${this.lower(input.dataset.position)}`).join(' · ');
            const last = picked[picked.length - 1];
            const note = last.dataset.freed === '1'
                ? 'Un passager occupe cette place avant votre montée, elle est libre pour vous.'
                : `Place ${last.value}, ${this.lower(last.dataset.position)}. Personne d’autre ne l’occupe sur votre bout de route.`;
            this.hintTarget.textContent = note + (left > 0 ? ` Encore ${left} ${left > 1 ? 'places' : 'place'} à choisir.` : '');
            this.hintBoxTarget.classList.add('is-active');
        }

        if (left <= 0) {
            this.submitTarget.disabled = false;
            this.submitTarget.textContent = `Réserver ${picked.length > 1 ? 'les places' : 'la place'} ${list}`;
        } else {
            this.submitTarget.disabled = true;
            this.submitTarget.textContent = picked.length === 0
                ? (this.countValue > 1 ? `Choisissez ${this.countValue} places` : 'Choisissez une place')
                : `Encore ${left} ${left > 1 ? 'places' : 'place'} à choisir`;
        }
    }

    list(codes) {
        return codes.length <= 1 ? codes.join('') : `${codes.slice(0, -1).join(', ')} et ${codes[codes.length - 1]}`;
    }

    lower(text) {
        return text.charAt(0).toLowerCase() + text.slice(1);
    }
}

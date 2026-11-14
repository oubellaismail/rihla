import { Controller } from '@hotwired/stimulus';

/*
 * Landing page: a worked example of paying only for your portion of a trip.
 * The server renders Essaouira → Marrakech; click a departure, then an arrival, to try another one.
 */
export default class extends Controller {
    static targets = ['stop', 'leg', 'hint', 'route', 'price', 'full', 'saving'];
    static values = { legs: Array, start: Number, end: Number };

    connect() {
        // `end` is null while the visitor has picked a departure and not yet an arrival.
        this.picked = { start: this.startValue, end: this.endValue };
        this.choosingArrival = false;
    }

    pick(event) {
        const index = Number(event.currentTarget.dataset.index);

        if (!this.choosingArrival || index <= this.picked.start) {
            // First click, or a stop before the current departure: it becomes the departure.
            this.picked = { start: index, end: null };
            this.choosingArrival = index < this.stopTargets.length - 1;
        } else {
            this.picked.end = index;
            this.choosingArrival = false;
        }
        this.render();
    }

    render() {
        const { start, end } = this.picked;
        const legs = this.legsValue;
        const total = legs.reduce((sum, price) => sum + price, 0);
        const name = (i) => this.stopTargets[i].querySelector('.fare-demo__name').textContent.trim();

        this.stopTargets.forEach((stop, i) => {
            const isEnd = i === start || i === end;
            stop.classList.toggle('is-on', end !== null ? i >= start && i <= end : i === start);
            stop.classList.toggle('is-end', isEnd);
            stop.setAttribute('aria-pressed', isEnd ? 'true' : 'false');
        });
        this.legTargets.forEach((leg, i) => leg.classList.toggle('is-on', end !== null && i >= start && i < end));
        this.hintTarget.textContent = this.choosingArrival ? 'Choisissez votre arrivée' : 'Choisissez votre départ';

        if (end === null) {
            this.routeTarget.textContent = `${name(start)} → …`;
            this.priceTarget.textContent = '–';
            this.fullTarget.hidden = true;
            this.savingTarget.textContent = 'Choisissez votre arrivée';
            return;
        }

        const portion = legs.slice(start, end).reduce((sum, price) => sum + price, 0);
        this.routeTarget.textContent = `${name(start)} → ${name(end)}`;
        this.priceTarget.textContent = String(portion);
        this.fullTarget.textContent = `Trajet complet · ${total} MAD`;
        this.fullTarget.hidden = portion === total;
        this.savingTarget.textContent = portion === total ? 'C’est le trajet complet' : `Vous gardez ${total - portion} MAD en poche`;
    }
}

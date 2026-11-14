import { Controller } from '@hotwired/stimulus';

/*
 * Stops of a trip: the form starts with a departure and an arrival, the driver
 * inserts intermediate stops between them. The arrival always stays last.
 */
export default class extends Controller {
    static targets = ['list', 'item', 'label', 'removeButton', 'price'];
    static values = { prototype: String, index: Number };

    connect() {
        this.refresh();
    }

    add() {
        const html = this.prototypeValue.replaceAll('__name__', this.indexValue++);
        const holder = document.createElement('div');
        holder.innerHTML = html.trim();
        const row = holder.firstElementChild;
        this.listTarget.insertBefore(row, this.itemTargets[this.itemTargets.length - 1]);
        this.refresh();
        row.querySelector('select, input')?.focus();
    }

    remove(event) {
        event.currentTarget.closest('[data-stop-list-target="item"]').remove();
        this.refresh();
    }

    refresh() {
        const last = this.itemTargets.length - 1;
        this.itemTargets.forEach((item, i) => {
            const isFirst = i === 0;
            const isLast = i === last;
            item.querySelector('[data-stop-list-target="label"]').textContent =
                isFirst ? 'Départ' : isLast ? 'Arrivée' : `Arrêt ${i}`;
            const timeLabel = item.querySelector('label[for$="_departureAt"]');
            if (timeLabel) {
                timeLabel.textContent = isFirst ? 'Date et heure de départ' : isLast ? 'Arrivée prévue' : 'Départ de cet arrêt';
            }
            // Departure and arrival cannot be removed.
            item.querySelector('[data-stop-list-target="removeButton"]').hidden = isFirst || isLast;
            // The arrival has no leg after it, so no price.
            const price = item.querySelector('[data-stop-list-target="price"]');
            price.hidden = isLast;
            price.querySelectorAll('input').forEach((input) => { input.disabled = isLast; });
        });
    }
}

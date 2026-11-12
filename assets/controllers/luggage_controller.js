import { Controller } from '@hotwired/stimulus';

/*
 * Bags on the seat page. The number inputs already work on their own; this controller adds
 * − / + buttons around them, keeps the total within the per-place limit and the litres within
 * what is free on the portion, then updates the status sentence, the per-row reasons and the side summary.
 * The server checks everything again when the booking is made.
 */
export default class extends Controller {
    static targets = ['input', 'status', 'summary'];
    static values = { free: Number, max: Number, places: Number, sizes: Array };

    connect() {
        this.sizes = Object.fromEntries(this.sizesValue.map((size) => [size.key, size]));
        this.inputTargets.forEach((input) => this.enhance(input));
        this.render();
    }

    enhance(input) {
        const size = this.sizes[input.dataset.size];
        const minus = this.button('−', 'Retirer ' + size.indefinite, -1, input);
        const plus = this.button('+', 'Ajouter ' + size.indefinite, 1, input);
        input.classList.add('is-stepper');
        input.before(minus);
        input.after(plus);
        input.dataset.action = 'input->luggage#render change->luggage#render';
        input.closest('.luggage__row').classList.add('has-stepper');
        input.minus = minus;
        input.plus = plus;
    }

    button(symbol, label, delta, input) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'luggage__step';
        button.setAttribute('aria-label', label);
        button.textContent = symbol;
        button.addEventListener('click', () => {
            input.value = String(this.count(input) + delta);
            this.render();
        });
        return button;
    }

    count(input) {
        const value = parseInt(input.value, 10);
        return Number.isNaN(value) || value < 0 ? 0 : value;
    }

    /** Clamp every input to what the limits allow, then redraw everything that depends on it. */
    render() {
        const counts = {};
        this.inputTargets.forEach((input) => { counts[input.dataset.size] = this.count(input); });

        // Reduce the last-edited sizes first when the limits are exceeded (e.g. a typed value).
        const total = () => Object.values(counts).reduce((sum, n) => sum + n, 0);
        const litres = () => Object.entries(counts).reduce((sum, [key, n]) => sum + n * this.sizes[key].litres, 0);
        [...this.inputTargets].reverse().forEach((input) => {
            const key = input.dataset.size;
            while (counts[key] > 0 && (total() > this.maxValue || litres() > this.freeValue)) counts[key] -= 1;
        });

        const used = litres();
        const left = this.freeValue - used;
        const room = this.maxValue - total();

        this.inputTargets.forEach((input) => {
            const key = input.dataset.size;
            input.value = String(counts[key]);
            input.minus.disabled = counts[key] === 0;
            input.plus.disabled = room <= 0 || this.sizes[key].litres > left;
        });

        this.element.querySelectorAll('[data-luggage-why]').forEach((why) => {
            const size = this.sizes[why.dataset.luggageWhy];
            why.textContent = size.litres > left ? `Plus assez de place pour ${size.indefinite}` : '';
        });
        if (this.hasStatusTarget) this.statusTarget.textContent = this.status(counts, left, room);
        if (this.hasSummaryTarget) this.summaryTarget.textContent = this.summary(counts);
    }

    /** Same wording as LuggageExtension::status(). */
    status(counts, left, room) {
        const words = this.describe(left);
        const full = words === 'plein';
        if (Object.values(counts).every((n) => n === 0)) {
            return full ? 'Le coffre est plein sur votre portion.' : `Vous n'emportez rien en soute. Il reste de la ${words}.`;
        }
        const carried = `Vous emportez ${this.summary(counts)}. `;
        if (room <= 0) {
            return carried + `C'est le maximum pour ${this.placesValue === 1 ? 'votre place' : `vos ${this.placesValue} places`}.`;
        }
        return carried + (full ? 'Le coffre est plein sur votre portion.' : `Il reste encore de la ${words}.`);
    }

    /** Same wording as LuggageSize::describeBags(). */
    summary(counts) {
        const parts = Object.values(this.sizes)
            .filter((size) => counts[size.key] > 0)
            .map((size) => `${counts[size.key]} ${this.name(size, counts[size.key])}`);
        return parts.length === 0 ? 'Aucun bagage en soute' : parts.join(', ');
    }

    /** Same wording as TrunkSpace::describe(). */
    describe(litres) {
        if (litres >= 200) return `place pour ${Math.floor(litres / 100)} grandes valises`;
        if (litres >= 100) return 'place pour une grande valise';
        if (litres >= 70) return 'place pour une valise moyenne';
        if (litres >= 40) return 'place pour un bagage cabine';
        return 'plein';
    }

    name(size, count) {
        return count > 1 ? size.many : size.one;
    }
}

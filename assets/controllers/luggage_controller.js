import { Controller } from '@hotwired/stimulus';

/*
 * Bags on the seat page. The number inputs already work on their own; this controller adds
 * − / + buttons around them, keeps the total within the per-place limit and the litres within
 * what is free on the portion, then updates the trunk gauge, a hint and the side summary.
 * The server checks everything again when the booking is made.
 */
export default class extends Controller {
    static targets = ['input', 'bar', 'others', 'mine', 'othersText', 'mineText', 'freeText', 'freeWords', 'hint', 'summary'];
    static values = { usable: Number, free: Number, max: Number, sizes: Array };

    connect() {
        this.sizes = Object.fromEntries(this.sizesValue.map((size) => [size.key, size]));
        this.inputTargets.forEach((input) => this.enhance(input));
        this.render();
    }

    enhance(input) {
        const size = this.sizes[input.dataset.size];
        const minus = this.button('−', 'Retirer ' + this.indefinite(size), -1, input);
        const plus = this.button('+', 'Ajouter ' + this.indefinite(size), 1, input);
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

        const others = Math.max(0, this.usableValue - this.freeValue);
        const share = (value) => (this.usableValue > 0 ? Math.round((value * 1000) / this.usableValue) / 10 : 0);
        if (this.hasOthersTarget) this.othersTarget.style.width = `${share(others)}%`;
        if (this.hasMineTarget) this.mineTarget.style.width = `${share(used)}%`;
        if (this.hasBarTarget) {
            this.barTarget.setAttribute('aria-label', `${others} L pris par les autres voyageurs, ${used} L pour vous, ${left} L libres, sur ${this.usableValue} L comptés`);
        }
        if (this.hasOthersTextTarget) this.othersTextTarget.textContent = `${others} L`;
        if (this.hasMineTextTarget) this.mineTextTarget.textContent = `${used} L`;
        if (this.hasFreeTextTarget) this.freeTextTarget.textContent = `${left} L`;
        if (this.hasFreeWordsTarget) this.freeWordsTarget.textContent = this.describe(left);
        if (this.hasHintTarget) this.hintTarget.textContent = this.hint(left, room);
        if (this.hasSummaryTarget) this.summaryTarget.textContent = this.summary(counts);
    }

    hint(left, room) {
        if (room <= 0) {
            return 'Maximum atteint : au plus 2 bagages comptés par voyageur.';
        }
        const ordered = Object.values(this.sizes).sort((a, b) => b.litres - a.litres);
        const tooBig = ordered.find((size) => size.litres > left);
        if (!tooBig) return '';
        const fits = ordered.find((size) => size.litres <= left);
        const first = `Plus assez de place pour ${this.indefinite(tooBig)} sur cette portion : il reste ${left} L.`;
        if (!fits) return `${first} Rien d'autre ne passe.`;
        const text = this.indefinite(fits);
        return `${first} ${text.charAt(0).toUpperCase()}${text.slice(1)} passe encore.`;
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

    article(size) {
        return size.one.startsWith('bagage') ? 'un' : 'une';
    }

    indefinite(size) {
        return `${this.article(size)} ${size.one}`;
    }
}

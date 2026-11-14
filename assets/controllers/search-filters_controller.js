import { Controller } from '@hotwired/stimulus';

/*
 * Search results: changing a filter reloads the results at once.
 * Without JavaScript the « Appliquer » button does the same.
 */
export default class extends Controller {
    static targets = ['submit'];

    connect() {
        this.submitTargets.forEach((button) => { button.hidden = true; });
    }

    apply() {
        // Leave empty fields out of the address, so a shared link reads cleanly.
        const empty = [...this.element.elements].filter((field) => field.name && !field.disabled && field.value === '' && field.type !== 'radio');
        const anyBag = [...this.element.elements].find((field) => field.name === 'bag' && field.checked && field.value === '');
        if (anyBag) empty.push(...[...this.element.elements].filter((field) => field.name === 'bag'));
        empty.forEach((field) => { field.disabled = true; });
        this.element.requestSubmit();
        empty.forEach((field) => { field.disabled = false; });
    }
}

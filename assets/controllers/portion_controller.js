import { Controller } from '@hotwired/stimulus';

/*
 * Trip page: choosing a boarding or drop-off stop reloads the page on that portion at once.
 * Without JavaScript the « Changer de portion » button does the same.
 */
export default class extends Controller {
    static targets = ['submit'];

    connect() {
        this.submitTargets.forEach((button) => { button.hidden = true; });
    }

    apply() {
        this.element.requestSubmit();
    }
}

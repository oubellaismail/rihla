import { Controller } from '@hotwired/stimulus';

/*
 * Folded search form: on the phone the form hides behind a one-line summary,
 * on wider screens it is shown open (the summary itself is hidden by CSS).
 */
export default class extends Controller {
    connect() {
        this.element.open = window.matchMedia('(min-width: 641px)').matches;
    }
}

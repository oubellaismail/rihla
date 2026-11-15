import { Controller } from '@hotwired/stimulus';

/*
 * Asks for confirmation before a form is submitted.
 * <form data-controller="confirm" data-action="submit->confirm#ask" data-confirm="Supprimer ?">
 */
export default class extends Controller {
    ask(event) {
        if (!window.confirm(this.element.dataset.confirm)) {
            event.preventDefault();
        }
    }
}

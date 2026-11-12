import { Controller } from '@hotwired/stimulus';

/*
 * Demo accounts on the login page (dev only): fills the e-mail and password fields and moves
 * the focus to the submit button. Nothing is sent until the visitor clicks « Se connecter ».
 */
export default class extends Controller {
    static values = { password: { type: String, default: 'rihla2026' } };

    fill(event) {
        const form = document.querySelector('form[action$="/login"]');
        if (!form) {
            return;
        }
        form.querySelector('#username').value = event.currentTarget.dataset.email;
        form.querySelector('#password').value = this.passwordValue;
        form.querySelector('button[type="submit"]').focus();
    }
}

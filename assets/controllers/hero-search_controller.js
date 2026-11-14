import { Controller } from '@hotwired/stimulus';

/*
 * Home search bar: the swap button exchanges the departure and the arrival.
 */
export default class extends Controller {
    static targets = ['from', 'to'];

    swap() {
        const from = this.fromTarget.value;
        this.fromTarget.value = this.toTarget.value;
        this.toTarget.value = from;
    }
}

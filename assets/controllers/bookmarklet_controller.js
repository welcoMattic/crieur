import { Controller } from '@hotwired/stimulus';

/*
 * The bookmarklet is meant to be dragged to the bookmarks bar: a click on the settings page would
 * only send the settings page itself, so it explains instead.
 */
export default class extends Controller {
    static values = { hint: String };

    static targets = ['hint'];

    explain(event) {
        event.preventDefault();
        this.hintTarget.textContent = this.hintValue;
        this.hintTarget.classList.add('is-hint');
    }
}

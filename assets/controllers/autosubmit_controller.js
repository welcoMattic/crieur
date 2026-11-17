import { Controller } from '@hotwired/stimulus';

/*
 * Submits its form as soon as a choice changes (voice, language). requestSubmit() fires the submit
 * event, so the CSRF double-submit token and Turbo both take part.
 */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}

import { Controller } from '@hotwired/stimulus';
import { player } from '../lib/player.js';

/*
 * Submits its form as soon as a choice changes (voice, language). requestSubmit() fires the submit
 * event, so the CSRF double-submit token and Turbo both take part. A voice change also sends where
 * the listener is, so that the new voice is synthesized from there first.
 */
export default class extends Controller {
    static targets = ['from'];
    static values = { article: String };

    submit() {
        if (this.hasFromTarget) {
            const snapshot = player.snapshot();
            this.fromTarget.value = snapshot.id === this.articleValue && snapshot.position !== null ? String(snapshot.position) : '0';
        }
        this.element.requestSubmit();
    }
}

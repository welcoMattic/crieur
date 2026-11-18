import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';
import { player, storage } from '../lib/player.js';
import { refresh, watch } from '../lib/status.js';

const SPOKEN_KEY = 'crieur.showSpoken';
const AFTER_CHANGE_POLL_MS = 30000;
const USER_SCROLL_GRACE_MS = 4000;
const SCROLL_KEYS = new Set(['PageUp', 'PageDown', 'Home', 'End', 'ArrowUp', 'ArrowDown']);

// The article whose transcript is being edited: it stays so when the page morphs.
let editing = null;

/*
 * The read-along page of an article: highlights the chunk being read and its paragraph, follows it
 * gently unless the listener scrolled a moment ago, jumps to a sentence on click, and shows on
 * request the text as the voice reads it. In edit mode, a click leaves a passage (or a whole
 * paragraph) out of the reading, or puts it back. While the article is prepared, it refreshes the
 * page in place (Turbo morph) when the status, the revision or a spoken text changes.
 */
export default class extends Controller {
    static values = {
        id: String,
        statusUrl: String,
        status: String,
        revision: Number,
        segments: Number,
        autoplay: Boolean,
        labels: Object,
        passagesUrl: String,
        token: String,
    };

    static targets = ['state', 'listen', 'listenLabel', 'transcript', 'spokenToggle', 'legend', 'editToggle', 'editLegend', 'removedCount'];

    connect() {
        this.highlighted = null;
        this.userScrolledAt = 0;
        this.saving = 0;
        this.onPlayer = this.onPlayer.bind(this);
        this.onMorph = this.onMorph.bind(this);
        this.onUserScroll = () => {
            this.userScrolledAt = Date.now();
        };
        this.onScrollKey = (event) => SCROLL_KEYS.has(event.key) && this.onUserScroll();
        // Voice, language, retry: the server changes the article, the worker follows. Watch closely.
        this.onSubmitted = (event) => event.detail?.success && refresh(this.statusUrlValue, AFTER_CHANGE_POLL_MS);

        player.addEventListener('change', this.onPlayer);
        document.addEventListener('turbo:morph', this.onMorph);
        this.element.addEventListener('turbo:submit-end', this.onSubmitted);
        window.addEventListener('wheel', this.onUserScroll, { passive: true });
        window.addEventListener('touchmove', this.onUserScroll, { passive: true });
        window.addEventListener('keydown', this.onScrollKey);
        this.unwatch = watch(this.statusUrlValue, (data) => this.onStatus(data));

        if (this.autoplayValue) {
            player.autoplay(this.idValue);
        } else if (this.statusValue !== 'failed' && player.isAvailable() && player.id !== this.idValue) {
            player.load(this.idValue);
        }
        this.applySpoken();
        this.applyEdit();
        this.onPlayer();
    }

    disconnect() {
        player.removeEventListener('change', this.onPlayer);
        document.removeEventListener('turbo:morph', this.onMorph);
        this.element.removeEventListener('turbo:submit-end', this.onSubmitted);
        window.removeEventListener('wheel', this.onUserScroll);
        window.removeEventListener('touchmove', this.onUserScroll);
        window.removeEventListener('keydown', this.onScrollKey);
        this.unwatch?.();
        clearTimeout(this.refreshTimer);
        this.tip?.remove();
    }

    // Actions.

    listen() {
        if (player.id === this.idValue) {
            player.toggle();
        } else {
            player.load(this.idValue, { play: true });
        }
    }

    seek(event) {
        const segment = event.target.closest('.seg');
        if (!segment || window.getSelection()?.toString()) {
            return;
        }
        if (this.editing()) {
            // The title is not edited here: preparing the article again would bring it back.
            if (this.hasTranscriptTarget && this.transcriptTarget.contains(segment)) {
                this.setRemoved([segment], !segment.classList.contains('is-removed'));
            }
            return;
        }
        if (segment.classList.contains('is-removed')) {
            return;
        }
        player.load(this.idValue, { play: true, position: Number(segment.dataset.position) });
    }

    // Choosing what is read.

    toggleEdit() {
        editing = this.editing() ? null : this.idValue;
        this.applyEdit();
    }

    toggleParagraph(event) {
        const paragraph = event.currentTarget.closest('.para');
        const segments = [...(paragraph?.querySelectorAll('.seg') ?? [])];
        if (segments.length > 0) {
            this.setRemoved(segments, !segments.every((segment) => segment.classList.contains('is-removed')));
        }
    }

    /** In edit mode, Enter or Space on a passage does what a click does. */
    keyEdit(event) {
        const segment = event.target.closest?.('.seg');
        if (!segment || !this.editing() || !['Enter', ' '].includes(event.key)) {
            return;
        }
        event.preventDefault();
        this.setRemoved([segment], !segment.classList.contains('is-removed'));
    }

    editing() {
        return editing === this.idValue;
    }

    applyEdit() {
        const on = this.editing();
        if (!this.hasTranscriptTarget) {
            return;
        }
        this.transcriptTarget.toggleAttribute('data-editing', on);
        for (const segment of this.transcriptTarget.querySelectorAll('.seg')) {
            if (on) {
                segment.setAttribute('role', 'button');
                segment.setAttribute('tabindex', '0');
                segment.setAttribute('aria-pressed', String(segment.classList.contains('is-removed')));
            } else {
                segment.removeAttribute('role');
                segment.removeAttribute('tabindex');
                segment.removeAttribute('aria-pressed');
            }
        }
        if (this.hasEditToggleTarget) {
            this.editToggleTarget.setAttribute('aria-pressed', String(on));
        }
        if (this.hasEditLegendTarget) {
            this.editLegendTarget.hidden = !on;
        }
    }

    /** Shows the change at once, then saves it; the player gets the new timeline from the status. */
    async setRemoved(segments, removed) {
        const before = segments.map((segment) => segment.classList.contains('is-removed'));
        this.markRemoved(segments, () => removed);
        const body = new FormData();
        body.set('_token', this.tokenValue);
        body.set('positions', segments.map((segment) => segment.dataset.position).join(','));
        body.set('removed', removed ? '1' : '0');
        this.saving += 1;
        try {
            const response = await fetch(this.passagesUrlValue, { method: 'POST', body, headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
        } catch {
            this.markRemoved(segments, (segment, i) => before[i]);
            window.alert(this.labelsValue.failed);
        } finally {
            this.saving -= 1;
        }
        refresh(this.statusUrlValue, AFTER_CHANGE_POLL_MS);
    }

    markRemoved(segments, removedFor) {
        segments.forEach((segment, i) => {
            const removed = removedFor(segment, i);
            segment.classList.toggle('is-removed', removed);
            segment.title = removed ? this.labelsValue.removed : '';
            if (segment.hasAttribute('aria-pressed')) {
                segment.setAttribute('aria-pressed', String(removed));
            }
            const spoken = segment.querySelector('.seg__spoken');
            if (spoken && removed) {
                spoken.dataset.skipped = this.labelsValue.removed;
            } else if (spoken && spoken.textContent.trim() !== '') {
                delete spoken.dataset.skipped;
            }
        });
        for (const paragraph of new Set(segments.map((segment) => segment.closest('.para')))) {
            if (!paragraph) {
                continue;
            }
            const out = [...paragraph.querySelectorAll('.seg')].every((segment) => segment.classList.contains('is-removed'));
            paragraph.classList.toggle('is-removed', out);
            paragraph.querySelector('.para__toggle')?.setAttribute('aria-pressed', String(out));
        }
        if (this.hasRemovedCountTarget) {
            const count = this.transcriptTarget.querySelectorAll('.seg.is-removed').length;
            const [one, many] = this.labelsValue.removedCount.split('|');
            this.removedCountTarget.textContent = count === 0 ? '' : (count === 1 ? one : many.replace('%count%', String(count)));
        }
    }

    toggleSpoken() {
        storage.set(SPOKEN_KEY, this.spokenOn() ? '0' : '1');
        this.applySpoken();
    }

    // Status of the article.

    onStatus(data) {
        if (data.gone) {
            return;
        }
        const changed = data.status !== this.statusValue
            || data.revision !== this.revisionValue
            || data.segments.length !== this.segmentsValue
            || data.segments.some((segment) => {
                const element = this.segmentElement(segment.position);

                return element !== null && (element.dataset.spokenHash !== segment.spokenHash
                    // Removed elsewhere (another tab): not while a change of this page is saved.
                    || (this.saving === 0 && element.classList.contains('is-removed') !== Boolean(segment.removed)));
            });
        if (changed) {
            this.refreshPage();
            return;
        }
        if (data.status === 'synthesizing' && this.hasStateTarget) {
            const text = this.labelsValue.synthesizing
                .replace('%ready%', String(data.readySegments))
                .replace('%total%', String(data.segments.length));
            if (this.stateTarget.textContent !== text) {
                this.stateTarget.textContent = text;
            }
        }
    }

    /** A page refresh morphs the page in place: scroll position and player bar are kept. */
    refreshPage() {
        if (this.refreshTimer) {
            return;
        }
        this.refreshTimer = setTimeout(() => {
            this.refreshTimer = null;
        }, 1500);
        visit(window.location.href, { action: 'replace' });
    }

    onMorph() {
        this.highlighted = null;
        this.applySpoken();
        this.applyEdit();
        this.onPlayer();
    }

    // Player.

    onPlayer() {
        const snapshot = player.snapshot();
        const mine = snapshot.id === this.idValue;
        const active = mine && (['playing', 'buffering'].includes(snapshot.state) || (snapshot.state === 'loading' && player.wantsToPlay));

        if (this.hasListenTarget) {
            const labels = this.labelsValue;
            this.listenTarget.classList.toggle('is-playing', active);
            this.listenLabelTarget.textContent = active
                ? labels.pause
                : (mine && snapshot.state === 'paused' && snapshot.started ? labels.resume : labels.listen);
        }

        const shown = mine && (['playing', 'buffering', 'paused'].includes(snapshot.state) || (snapshot.state === 'idle' && snapshot.index > 0));
        this.highlight(shown ? snapshot.position : null, snapshot.state === 'playing');
    }

    highlight(position, follow) {
        if (position === this.highlighted) {
            return;
        }
        for (const element of this.element.querySelectorAll('.seg.is-current, .para.is-reading')) {
            element.classList.remove('is-current', 'is-reading');
        }
        this.highlighted = position;
        const segment = position === null ? null : this.segmentElement(position);
        if (!segment) {
            return;
        }
        segment.classList.add('is-current');
        segment.closest('.para')?.classList.add('is-reading');
        if (follow) {
            this.follow(segment);
        }
    }

    /** Keeps the sentence being read in view, unless the listener scrolled in the last seconds. */
    follow(segment) {
        if (Date.now() - this.userScrolledAt < USER_SCROLL_GRACE_MS) {
            return;
        }
        const rect = segment.getBoundingClientRect();
        const viewport = window.innerHeight;
        const bar = document.getElementById('player')?.offsetHeight ?? 0;
        if (rect.top >= viewport * 0.18 && rect.bottom <= viewport - bar - viewport * 0.12) {
            return;
        }
        const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        window.scrollTo({ top: window.scrollY + rect.top - viewport * 0.35, behavior: reduce ? 'auto' : 'smooth' });
    }

    // What the voice reads.

    /** In the spoken view, a rewritten chunk shows its original text above it. */
    showOriginal(event) {
        const segment = this.spokenOn() ? event.target.closest('.seg[data-rewritten]') : null;
        if (!segment) {
            this.hideOriginal();
            return;
        }
        const tip = this.originalTip();
        tip.lastElementChild.textContent = segment.querySelector('.seg__text')?.textContent ?? '';
        tip.hidden = false;
        const rect = segment.getClientRects()[0] ?? segment.getBoundingClientRect();
        const width = tip.offsetWidth;
        const top = rect.top - tip.offsetHeight - 10;
        tip.style.top = `${top < 8 ? rect.bottom + 10 : top}px`;
        tip.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - width - 8))}px`;
    }

    hideOriginal(event) {
        if (event?.relatedTarget && event.relatedTarget.closest?.('.seg') === event.target.closest?.('.seg')) {
            return;
        }
        if (this.tip) {
            this.tip.hidden = true;
        }
    }

    originalTip() {
        if (!this.tip?.isConnected) {
            this.tip = document.createElement('div');
            this.tip.className = 'original-tip';
            this.tip.setAttribute('role', 'tooltip');
            this.tip.hidden = true;
            const label = document.createElement('span');
            label.className = 'original-tip__label';
            label.textContent = this.labelsValue.original;
            this.tip.append(label, document.createElement('span'));
            document.body.append(this.tip);
        }

        return this.tip;
    }

    spokenOn() {
        return storage.get(SPOKEN_KEY) === '1';
    }

    applySpoken() {
        const on = this.spokenOn();
        document.documentElement.toggleAttribute('data-show-spoken', on);
        if (!on) {
            this.hideOriginal();
        }
        if (this.hasSpokenToggleTarget) {
            this.spokenToggleTarget.setAttribute('aria-pressed', String(on));
        }
        if (this.hasLegendTarget) {
            this.legendTarget.hidden = !on;
        }
    }

    segmentElement(position) {
        return this.element.querySelector(`.seg[data-position="${position}"]`);
    }
}

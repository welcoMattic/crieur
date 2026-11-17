import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';
import { player, storage } from '../lib/player.js';
import { refresh, watch } from '../lib/status.js';

const SPOKEN_KEY = 'crieur.showSpoken';
const AFTER_CHANGE_POLL_MS = 30000;
const USER_SCROLL_GRACE_MS = 4000;
const SCROLL_KEYS = new Set(['PageUp', 'PageDown', 'Home', 'End', 'ArrowUp', 'ArrowDown']);

/*
 * The read-along page of an article: highlights the chunk being read and its paragraph, follows it
 * gently unless the listener scrolled a moment ago, jumps to a sentence on click, and shows on
 * request the text as the voice reads it. While the article is prepared, it refreshes the page
 * in place (Turbo morph) when the status, the revision or a spoken text changes.
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
    };

    static targets = ['state', 'listen', 'listenLabel', 'transcript', 'spokenToggle', 'legend'];

    connect() {
        this.highlighted = null;
        this.userScrolledAt = 0;
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
        player.load(this.idValue, { play: true, position: Number(segment.dataset.position) });
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

                return element !== null && element.dataset.spokenHash !== segment.spokenHash;
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

import { Controller } from '@hotwired/stimulus';
import { player } from '../lib/player.js';

/*
 * The player bar: renders the state of the audio engine and sends it the listener's commands,
 * from its buttons, the keyboard (Space, Left, Right, Escape) and the speed panel.
 * The bar is data-turbo-permanent: this controller disconnects and reconnects at each Turbo
 * visit, the audio engine (lib/player.js) does not.
 */
export default class extends Controller {
    static values = { statusUrl: String, labels: Object };

    static targets = [
        'progress', 'progressFill', 'stateText', 'title', 'position', 'remaining',
        'toggle', 'previous', 'next', 'stop',
        'speedButton', 'speedLabel', 'speedPanel', 'speedOutput', 'speedRange', 'speedPreset',
    ];

    connect() {
        this.render = this.render.bind(this);
        this.renderTime = this.renderTime.bind(this);
        this.onKey = this.onKey.bind(this);
        player.addEventListener('change', this.render);
        player.addEventListener('time', this.renderTime);
        document.addEventListener('keydown', this.onKey);
        this.labelButtons();
        player.restore();
        this.render();
    }

    disconnect() {
        player.removeEventListener('change', this.render);
        player.removeEventListener('time', this.renderTime);
        document.removeEventListener('keydown', this.onKey);
    }

    // Actions.

    toggle() {
        player.toggle();
    }

    previous() {
        player.previousParagraph();
    }

    next() {
        player.nextParagraph();
    }

    stop() {
        player.stop();
    }

    speedInput() {
        player.setSpeed(this.speedRangeTarget.value);
    }

    speedPreset({ params: { speed } }) {
        player.setSpeed(speed);
    }

    // Rendering.

    render() {
        const snapshot = player.snapshot();
        const loaded = snapshot.state !== 'empty';
        this.element.hidden = !loaded;
        document.documentElement.classList.toggle('has-player', loaded);
        if (!loaded) {
            return;
        }

        const labels = this.labelsValue;
        const article = snapshot.article;
        const display = snapshot.display;
        this.element.dataset.state = display;

        let stateText = labels.states[display] ?? '';
        if (display === 'failed' && article?.failureMessage) {
            stateText = article.failureMessage;
        }
        if (this.stateTextTarget.textContent !== stateText) {
            this.stateTextTarget.textContent = stateText;
        }

        this.titleTarget.textContent = article?.title ?? labels.states.loading;
        this.titleTarget.title = article?.title ?? '';
        if (article?.page) {
            this.titleTarget.href = article.page;
        }

        const active = ['playing', 'buffering'].includes(snapshot.state) || (snapshot.state === 'loading' && player.wantsToPlay);
        this.toggleTarget.classList.toggle('is-playing', active);
        const toggleLabel = active ? labels.pause : (snapshot.state === 'paused' && snapshot.started ? labels.resume : labels.play);
        this.toggleTarget.setAttribute('aria-label', toggleLabel);
        this.toggleTarget.title = `${toggleLabel} (${this.keyName('Space')})`;

        const hasSegments = (article?.segments.length ?? 0) > 0;
        this.previousTarget.disabled = !hasSegments;
        this.nextTarget.disabled = !hasSegments;
        this.toggleTarget.disabled = snapshot.state === 'failed';
        this.stopTarget.disabled = ['idle', 'stopped', 'finished', 'failed'].includes(snapshot.state);

        const total = snapshot.paragraphTotal;
        const current = snapshot.state === 'finished' ? total : snapshot.paragraphNumber;
        this.progressFillTarget.style.transform = `scaleX(${total > 0 ? current / total : 0})`;
        this.progressTarget.setAttribute('aria-valuemax', String(Math.max(total, 1)));
        this.progressTarget.setAttribute('aria-valuenow', String(current));
        this.positionTarget.textContent = total > 0 ? this.format(labels.paragraph, { current, total }) : '';
        this.renderTime();
        this.renderSpeed(snapshot.speed);
    }

    renderTime() {
        const snapshot = player.snapshot();
        const labels = this.labelsValue;
        const remaining = snapshot.remainingMs;
        if (remaining === null || snapshot.paragraphTotal === 0) {
            this.remainingTarget.textContent = '';
            this.progressTarget.removeAttribute('aria-valuetext');
            return;
        }
        const seconds = Math.round(remaining / 1000);
        const text = this.format(labels.remaining, { time: this.clock(seconds) });
        if (this.remainingTarget.textContent !== text) {
            this.remainingTarget.textContent = text;
            this.remainingTarget.setAttribute('aria-label', this.format(labels.remainingSpoken, { time: this.spokenDuration(seconds) }));
            const current = snapshot.state === 'finished' ? snapshot.paragraphTotal : snapshot.paragraphNumber;
            this.progressTarget.setAttribute('aria-valuetext', `${this.format(labels.paragraph, { current, total: snapshot.paragraphTotal })}, ${this.format(labels.remainingSpoken, { time: this.spokenDuration(seconds) })}`);
        }
    }

    renderSpeed(speed) {
        const labels = this.labelsValue;
        const value = this.format(labels.speedValue, { speed: this.number(speed) });
        this.speedLabelTarget.textContent = value;
        this.speedOutputTarget.textContent = value;
        this.speedButtonTarget.setAttribute('aria-label', `${labels.speed}, ${this.format(labels.speedSpoken, { speed: this.number(speed) })}`);
        this.speedButtonTarget.title = labels.speed;
        if (Number(this.speedRangeTarget.value) !== speed) {
            this.speedRangeTarget.value = String(speed);
        }
        this.speedRangeTarget.setAttribute('aria-valuetext', this.format(labels.speedSpoken, { speed: this.number(speed) }));
        for (const button of this.speedPresetTargets) {
            const preset = Number(button.dataset.speed);
            button.textContent = this.format(labels.speedValue, { speed: this.number(preset) });
            button.setAttribute('aria-pressed', String(Math.abs(preset - speed) < 0.001));
        }
    }

    labelButtons() {
        const labels = this.labelsValue;
        for (const [target, label, key] of [
            [this.previousTarget, labels.previous, 'ArrowLeft'],
            [this.nextTarget, labels.next, 'ArrowRight'],
            [this.stopTarget, labels.stop, 'Escape'],
        ]) {
            target.setAttribute('aria-label', label);
            target.title = `${label} (${this.keyName(key)})`;
        }
    }

    // Keyboard: only when the focus is not in a field, and Space not on another control.

    onKey(event) {
        if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey || player.state === 'empty') {
            return;
        }
        const target = event.target instanceof Element ? event.target : null;
        if (target?.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"])')) {
            return;
        }
        switch (event.key) {
            case ' ':
                if (target?.closest('button, a, summary, [role="button"]')) {
                    return;
                }
                event.preventDefault();
                player.toggle();
                break;
            case 'ArrowLeft':
                event.preventDefault();
                player.previousParagraph();
                break;
            case 'ArrowRight':
                event.preventDefault();
                player.nextParagraph();
                break;
            case 'Escape':
                if (this.speedPanelTarget.matches(':popover-open')) {
                    return;
                }
                player.stop();
                break;
            default:
        }
    }

    // Formatting.

    format(template, values) {
        return Object.entries(values).reduce((text, [key, value]) => text.replaceAll(`%${key}%`, String(value)), template);
    }

    number(value) {
        return new Intl.NumberFormat(this.labelsValue.locale, { maximumFractionDigits: 2 }).format(value);
    }

    clock(seconds) {
        const minutes = Math.floor(seconds / 60);
        const hours = Math.floor(minutes / 60);
        const ss = String(seconds % 60).padStart(2, '0');

        return hours > 0 ? `${hours}:${String(minutes % 60).padStart(2, '0')}:${ss}` : `${minutes}:${ss}`;
    }

    spokenDuration(seconds) {
        const labels = this.labelsValue;
        const minutes = Math.floor(seconds / 60);

        return minutes > 0
            ? this.format(labels.minutesSeconds, { minutes, seconds: seconds % 60 })
            : this.format(labels.seconds, { seconds });
    }

    keyName(key) {
        const labels = this.labelsValue;

        return { Space: labels.keySpace, ArrowLeft: '←', ArrowRight: '→', Escape: labels.keyEscape }[key] ?? key;
    }
}

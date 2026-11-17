/*
 * The audio engine of Crieur, one for the whole page session.
 *
 * It plays the chunks of an article in order with two alternating audio elements (the next chunk
 * preloads while the current one plays) and waits the pause of each chunk between them. It lives
 * in this module, not in a Stimulus controller: Turbo detaches and reattaches the player bar at
 * each visit, and nothing here may stop because a controller disconnects.
 *
 * States: empty (nothing loaded), loading (first status awaited), idle (loaded, not started),
 * buffering (waiting for audio), playing, paused, stopped, finished, error (a chunk failed twice),
 * failed (the article could not be prepared). Controllers listen to "change" and read snapshot().
 */

import { refresh, watch } from './status.js';

const RESUME_BUFFER_MS = 2000; // R-PB-11: resume with 2 s of audio ready, or every chunk left
const RESTART_PARAGRAPH_MS = 3000; // R-PB-8: "previous" restarts the paragraph after 3 s of it
const FALLBACK_MS_PER_CHAR = 65;
const SPEED_MIN = 0.75;
const SPEED_MAX = 2;

const keys = {
    speed: 'crieur.speed',
    current: 'crieur.current',
    position: (id) => `crieur.position.${id}`,
};

export const storage = {
    get(key) {
        try {
            return window.localStorage.getItem(key);
        } catch {
            return null;
        }
    },
    set(key, value) {
        try {
            window.localStorage.setItem(key, String(value));
        } catch {
            // Private browsing or storage disabled: the setting lasts for this page only.
        }
    },
    remove(key) {
        try {
            window.localStorage.removeItem(key);
        } catch {
            // Same as above.
        }
    },
};

export function clampSpeed(value) {
    const speed = Math.round(Number(value) * 20) / 20;

    return Number.isFinite(speed) ? Math.min(SPEED_MAX, Math.max(SPEED_MIN, speed)) : 1;
}

function createAudio() {
    const audio = new Audio();
    audio.preload = 'auto';
    audio.preservesPitch = true;
    audio.mozPreservesPitch = true;
    audio.webkitPreservesPitch = true;
    audio.crieurUrl = null;

    return audio;
}

/** 50 ms of silence: played once on a user gesture so that browsers allow later playback. */
function silentWavUrl() {
    const samples = 400;
    const buffer = new ArrayBuffer(44 + samples * 2);
    const view = new DataView(buffer);
    const text = (offset, value) => [...value].forEach((char, i) => view.setUint8(offset + i, char.charCodeAt(0)));
    text(0, 'RIFF');
    view.setUint32(4, 36 + samples * 2, true);
    text(8, 'WAVEfmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true);
    view.setUint16(22, 1, true);
    view.setUint32(24, 8000, true);
    view.setUint32(28, 16000, true);
    view.setUint16(32, 2, true);
    view.setUint16(34, 16, true);
    text(36, 'data');
    view.setUint32(40, samples * 2, true);

    return URL.createObjectURL(new Blob([buffer], { type: 'audio/wav' }));
}

class Player extends EventTarget {
    constructor() {
        super();
        this.id = null;
        this.article = null;
        this.state = 'empty';
        this.index = 0;
        this.started = false; // the active element holds the current chunk, started
        this.wantsToPlay = false;
        this.speed = clampSpeed(storage.get(keys.speed) ?? 1);
        this.audios = [createAudio(), createAudio()];
        this.active = 0;
        this.gapTimer = null;
        this.unwatch = null;
        this.failures = 0;
        this.playToken = 0;
        this.restored = false;
        this.autoplayed = new Set();
        this.primed = false;
        this.metadataFor = null;

        this.audios.forEach((audio, n) => {
            audio.addEventListener('ended', () => n === this.active && this.onEnded());
            audio.addEventListener('error', () => n === this.active && this.started && this.onAudioError());
            audio.addEventListener('timeupdate', () => n === this.active && this.dispatchEvent(new Event('time')));
        });
        this.setUpMediaSession();
    }

    // Loading.

    /** Reopens the article of the last session, paused at its position. */
    restore() {
        if (this.restored) {
            return;
        }
        this.restored = true;
        const id = storage.get(keys.current);
        if (id && !this.id) {
            this.load(id);
        }
    }

    /** True when nothing worth keeping is loaded: another article may take the player. */
    isAvailable() {
        return ['empty', 'idle', 'stopped', 'finished', 'failed', 'error'].includes(this.state);
    }

    load(id, { play = false, position = null } = {}) {
        if (play) {
            this.prime();
        }
        if (this.id === id) {
            if (position !== null) {
                this.seek(position, play);
            } else if (play) {
                this.play();
            }

            return;
        }

        this.halt();
        this.unwatch?.();
        this.id = id;
        this.article = null;
        this.failures = 0;
        this.metadataFor = null;
        this.index = position ?? (Number.parseInt(storage.get(keys.position(id)) ?? '0', 10) || 0);
        this.wantsToPlay = play;
        this.state = 'loading';
        storage.set(keys.current, id);
        this.unwatch = watch(this.statusUrl(id), (data) => this.onStatus(data));
        this.emit();
    }

    /** Plays the article once per page session when its page asks for it (after adding it). */
    autoplay(id) {
        if (this.autoplayed.has(id)) {
            return;
        }
        this.autoplayed.add(id);
        this.load(id, { play: true });
    }

    unload() {
        this.halt();
        this.unwatch?.();
        this.unwatch = null;
        this.id = null;
        this.article = null;
        this.state = 'empty';
        storage.remove(keys.current);
        this.emit();
    }

    statusUrl(id) {
        const template = document.getElementById('player')?.dataset.playerStatusUrlValue ?? '/articles/__ID__/status';

        return template.replace('__ID__', encodeURIComponent(id));
    }

    onStatus(data) {
        if (data.gone) {
            this.unload();
            return;
        }

        const previous = this.article;
        this.article = data;
        const segments = data.segments;

        if (previous && previous.segments.length !== segments.length) {
            // Prepared again (another language): go on from the same paragraph, or from the start.
            const paragraph = previous.segments[this.index]?.paragraph ?? 0;
            this.halt();
            this.index = Math.max(0, segments.findIndex((segment) => segment.paragraph >= paragraph));
            if (['playing', 'buffering'].includes(this.state)) {
                this.toBuffering();
            }
        } else if (previous && previous.revision !== data.revision && this.state === 'playing') {
            // Another voice: the chunk being read is read again with it.
            this.halt();
            this.toBuffering();
        }
        // Forget a preloaded chunk whose audio was replaced (another voice, a rewrite): its address is stale.
        const urls = new Set(segments.map((segment) => segment.url).filter(Boolean));
        this.audios.forEach((audio, n) => {
            if (audio.crieurUrl && !urls.has(audio.crieurUrl) && !(this.started && n === this.active)) {
                audio.removeAttribute('src');
                audio.load();
                audio.crieurUrl = null;
            }
        });
        if (this.index >= segments.length && segments.length > 0 && !data.inProgress) {
            this.index = 0;
        }

        if (data.status === 'failed' && !data.playable) {
            this.halt();
            this.state = 'failed';
        } else if (this.state === 'loading') {
            this.state = this.wantsToPlay ? 'buffering' : 'idle';
        } else if (this.state === 'failed') {
            this.state = 'idle';
        }

        if (this.state === 'buffering') {
            this.tryResume();
        } else {
            this.preloadNext();
        }
        this.emit();
    }

    // Transport.

    play() {
        if (!this.id) {
            return;
        }
        this.prime();
        if (['playing', 'buffering', 'loading', 'failed'].includes(this.state)) {
            this.wantsToPlay = this.state !== 'failed';
            return;
        }
        if (this.state === 'paused' && this.started) {
            const audio = this.audios[this.active];
            audio.playbackRate = this.speed;
            this.state = 'playing';
            this.startAudio(audio);
            this.emit();
            return;
        }
        if (this.state === 'finished') {
            this.index = 0;
        }
        this.failures = 0;
        this.toBuffering();
        this.tryResume();
        this.emit();
    }

    pause() {
        if (this.state === 'playing') {
            if (this.gapTimer) {
                // Between two chunks: the next play starts the next chunk.
                clearTimeout(this.gapTimer);
                this.gapTimer = null;
                this.index += 1;
                this.started = false;
            } else {
                this.audios[this.active].pause();
            }
            this.state = 'paused';
        } else if (this.state === 'buffering' || this.state === 'loading') {
            this.wantsToPlay = false;
            this.state = this.state === 'loading' ? 'loading' : 'paused';
        } else {
            return;
        }
        this.savePosition();
        this.emit();
    }

    toggle() {
        if (['playing', 'buffering'].includes(this.state) || (this.state === 'loading' && this.wantsToPlay)) {
            this.pause();
        } else {
            this.play();
        }
    }

    stop() {
        if (!this.id || ['empty', 'idle', 'stopped', 'finished'].includes(this.state)) {
            return;
        }
        this.halt();
        this.wantsToPlay = false;
        this.index = 0;
        this.state = 'stopped';
        storage.remove(keys.position(this.id));
        this.emit();
    }

    seek(position, play = true) {
        const count = this.article?.segments.length ?? 0;
        this.halt();
        this.index = Math.max(0, count > 0 ? Math.min(position, count - 1) : position);
        this.failures = 0;
        if (play) {
            this.prime();
            this.toBuffering();
            this.tryResume();
        } else if (['playing', 'buffering'].includes(this.state)) {
            this.state = 'paused';
        } else if (['stopped', 'finished'].includes(this.state)) {
            this.state = 'idle';
        }
        this.savePosition();
        this.emit();
    }

    previousParagraph() {
        const segments = this.article?.segments ?? [];
        if (segments.length === 0) {
            return;
        }
        const index = Math.min(this.index, segments.length - 1);
        const paragraph = segments[index].paragraph;
        const start = segments.findIndex((segment) => segment.paragraph === paragraph);
        let elapsed = 0;
        for (let i = start; i < index; i++) {
            elapsed += (segments[i].durationMs ?? 0) + segments[i].pauseAfterMs;
        }
        if (this.started) {
            elapsed += this.audios[this.active].currentTime * 1000;
        }

        let target = start;
        if (elapsed / this.speed <= RESTART_PARAGRAPH_MS && start > 0) {
            const before = segments[start - 1].paragraph;
            target = segments.findIndex((segment) => segment.paragraph === before);
        }
        this.jump(target);
    }

    nextParagraph() {
        const segments = this.article?.segments ?? [];
        if (segments.length === 0) {
            return;
        }
        const paragraph = segments[Math.min(this.index, segments.length - 1)].paragraph;
        const target = segments.findIndex((segment, i) => i > this.index && segment.paragraph !== paragraph);
        if (target === -1) {
            if (!this.article.inProgress) {
                this.finish();
            }
            return;
        }
        this.jump(target);
    }

    jump(target) {
        this.seek(target, ['playing', 'buffering'].includes(this.state));
    }

    setSpeed(value) {
        this.speed = clampSpeed(value);
        storage.set(keys.speed, this.speed);
        for (const audio of this.audios) {
            audio.defaultPlaybackRate = this.speed;
            audio.playbackRate = this.speed;
        }
        this.emit();
    }

    // Internals.

    toBuffering() {
        this.wantsToPlay = true;
        this.state = 'buffering';
    }

    tryResume() {
        const article = this.article;
        if (!article || this.state !== 'buffering') {
            return;
        }
        const segments = article.segments;
        if (!article.playable || segments.length === 0) {
            return;
        }
        if (this.index >= segments.length) {
            if (!article.inProgress) {
                this.finish();
            }
            return;
        }

        let buffered = 0;
        let everyChunkLeft = true;
        for (let i = this.index; i < segments.length; i++) {
            if (!segments[i].ready) {
                everyChunkLeft = false;
                break;
            }
            buffered += ((segments[i].durationMs ?? 0) + segments[i].pauseAfterMs) / this.speed;
            if (buffered >= RESUME_BUFFER_MS) {
                break;
            }
        }
        if (segments[this.index].ready && (buffered >= RESUME_BUFFER_MS || everyChunkLeft)) {
            this.startSegment(this.index);
        }
    }

    startSegment(index) {
        clearTimeout(this.gapTimer);
        this.gapTimer = null;
        const segment = this.article.segments[index];
        this.index = index;
        if (!segment?.ready) {
            this.toBuffering();
            this.emit();
            return;
        }

        const other = 1 - this.active;
        if (this.audios[other].crieurUrl === segment.url) {
            this.active = other;
        }
        const audio = this.audios[this.active];
        this.audios[1 - this.active].pause();
        if (audio.crieurUrl !== segment.url) {
            audio.src = segment.url;
            audio.crieurUrl = segment.url;
        } else if (audio.currentTime > 0) {
            audio.currentTime = 0;
        }
        audio.defaultPlaybackRate = this.speed;
        audio.playbackRate = this.speed;
        this.started = true;
        this.wantsToPlay = false;
        this.state = 'playing';
        this.startAudio(audio);
        this.savePosition();
        this.preloadNext();
        this.emit();
    }

    startAudio(audio) {
        const token = ++this.playToken;
        audio.play().then(() => {
            if (token === this.playToken) {
                this.failures = 0;
            }
        }).catch((error) => {
            if (token !== this.playToken || error.name === 'AbortError') {
                return;
            }
            if (error.name === 'NotAllowedError') {
                // The browser wants a gesture first: wait for the listener to press Play.
                this.state = 'paused';
                this.emit();
            } else {
                this.onAudioError();
            }
        });
    }

    preloadNext() {
        const next = this.article?.segments[this.index + 1];
        if (!next?.ready) {
            return;
        }
        const audio = this.audios[1 - this.active];
        if (audio.crieurUrl !== next.url) {
            audio.src = next.url;
            audio.crieurUrl = next.url;
            audio.load();
        }
    }

    onEnded() {
        if (this.state !== 'playing' || !this.article) {
            return;
        }
        const segment = this.article.segments[this.index];
        const last = this.index + 1 >= this.article.segments.length;
        if (last && !this.article.inProgress) {
            this.finish();
            return;
        }

        this.gapTimer = setTimeout(() => {
            this.gapTimer = null;
            this.started = false;
            if (this.state !== 'playing') {
                return;
            }
            const next = this.index + 1;
            if (this.article.segments[next]?.ready) {
                this.startSegment(next);
            } else {
                // Underrun: wait for the synthesis, then resume with 2 s of audio ahead.
                this.index = next;
                this.toBuffering();
                refresh(this.statusUrl(this.id));
                this.savePosition();
                this.emit();
            }
        }, (segment?.pauseAfterMs ?? 0) / this.speed);
    }

    onAudioError() {
        // R-SM-4: one more try, then stop with the position kept.
        this.failures += 1;
        this.halt();
        if (this.failures >= 2) {
            this.state = 'error';
            this.wantsToPlay = false;
        } else {
            for (const audio of this.audios) {
                audio.crieurUrl = null;
            }
            this.toBuffering();
            refresh(this.statusUrl(this.id));
        }
        this.emit();
    }

    finish() {
        this.halt();
        this.wantsToPlay = false;
        this.index = Math.max(0, (this.article?.segments.length ?? 1) - 1);
        this.state = 'finished';
        storage.remove(keys.position(this.id));
        this.emit();
    }

    halt() {
        clearTimeout(this.gapTimer);
        this.gapTimer = null;
        this.playToken += 1;
        for (const audio of this.audios) {
            audio.pause();
        }
        this.started = false;
    }

    savePosition() {
        if (this.id && this.state !== 'finished') {
            storage.set(keys.position(this.id), this.index);
        }
    }

    /** Safari plays audio only from a user gesture: play a silence on both elements once. */
    prime() {
        if (this.primed) {
            return;
        }
        this.primed = true;
        const url = silentWavUrl();
        for (const audio of this.audios) {
            if (audio.crieurUrl === null) {
                audio.src = url;
                audio.play().then(() => audio.crieurUrl === null && audio.pause()).catch(() => {});
            }
        }
    }

    // What the interface shows.

    snapshot() {
        const article = this.article;
        const segments = article?.segments ?? [];
        const segment = segments[Math.min(this.index, segments.length - 1)] ?? null;
        const paragraphs = [...new Set(segments.map((s) => s.paragraph))];

        return {
            id: this.id,
            state: this.state,
            display: this.displayState(),
            article,
            index: this.index,
            started: this.started,
            position: segment?.position ?? null,
            paragraph: segment?.paragraph ?? null,
            paragraphNumber: segment ? paragraphs.indexOf(segment.paragraph) + 1 : 0,
            paragraphTotal: paragraphs.length,
            remainingMs: this.remainingMs(),
            speed: this.speed,
        };
    }

    displayState() {
        const article = this.article;
        if (!this.id) {
            return 'empty';
        }
        if (this.state === 'failed') {
            return 'failed';
        }
        if (article && !article.playable && ['idle', 'buffering', 'paused'].includes(this.state)) {
            return ['queued', 'extracting', 'preparing'].includes(article.status) ? article.status : this.state;
        }

        return this.state;
    }

    /** Time left at the current speed: real durations where known, else estimated from characters. */
    remainingMs() {
        const segments = this.article?.segments ?? [];
        if (segments.length === 0) {
            return null;
        }
        if (this.state === 'finished') {
            return 0;
        }
        let readyMs = 0;
        let readyChars = 0;
        for (const segment of segments) {
            if (segment.ready && segment.durationMs) {
                readyMs += segment.durationMs;
                readyChars += segment.chars;
            }
        }
        const msPerChar = readyChars >= 120 ? readyMs / readyChars : FALLBACK_MS_PER_CHAR;

        let total = 0;
        for (let i = Math.min(this.index, segments.length - 1); i < segments.length; i++) {
            const segment = segments[i];
            let ms = segment.ready && segment.durationMs ? segment.durationMs : segment.chars * msPerChar;
            if (i === this.index && this.started) {
                ms = Math.max(0, ms - this.audios[this.active].currentTime * 1000);
            }
            total += ms + segment.pauseAfterMs;
        }

        return total / this.speed;
    }

    emit() {
        this.updateMediaSession();
        this.dispatchEvent(new Event('change'));
    }

    // Media Session: title, site, media keys.

    setUpMediaSession() {
        if (!('mediaSession' in navigator)) {
            return;
        }
        const handlers = {
            play: () => this.play(),
            pause: () => this.pause(),
            stop: () => this.stop(),
            previoustrack: () => this.previousParagraph(),
            nexttrack: () => this.nextParagraph(),
            seekbackward: null,
            seekforward: null,
            seekto: null,
        };
        for (const [action, handler] of Object.entries(handlers)) {
            try {
                navigator.mediaSession.setActionHandler(action, handler);
            } catch {
                // Action not supported by this browser.
            }
        }
    }

    updateMediaSession() {
        if (!('mediaSession' in navigator)) {
            return;
        }
        const article = this.article;
        if (article && this.metadataFor !== `${article.id}:${article.title}` && 'MediaMetadata' in window) {
            this.metadataFor = `${article.id}:${article.title}`;
            navigator.mediaSession.metadata = new MediaMetadata({
                title: article.title,
                artist: article.site,
                album: 'Crieur',
                artwork: [
                    { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
                    { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
                ],
            });
        }
        navigator.mediaSession.playbackState = this.state === 'playing' || this.state === 'buffering'
            ? 'playing'
            : (this.state === 'paused' ? 'paused' : 'none');
    }
}

export const player = new Player();

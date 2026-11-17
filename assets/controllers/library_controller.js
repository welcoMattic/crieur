import { Controller } from '@hotwired/stimulus';
import { player } from '../lib/player.js';

const POLL_MS = 2000;
const IDLE_POLL_MS = 5000;

/*
 * The library list: reloads itself (a Turbo Frame, morphed in place) every 2 seconds while an
 * article is in progress, every 5 seconds otherwise while the tab is visible (articles also come
 * from an AI assistant over MCP, the bookmarklet or the console), and marks the article in the player.
 */
export default class extends Controller {
    static values = { labels: Object };

    connect() {
        this.onPlayer = this.onPlayer.bind(this);
        this.onLoad = () => {
            this.onPlayer();
            this.schedule();
        };
        this.onVisibility = () => this.schedule();
        player.addEventListener('change', this.onPlayer);
        this.element.addEventListener('turbo:frame-load', this.onLoad);
        document.addEventListener('visibilitychange', this.onVisibility);
        this.onPlayer();
        this.schedule();
    }

    disconnect() {
        clearTimeout(this.timer);
        player.removeEventListener('change', this.onPlayer);
        this.element.removeEventListener('turbo:frame-load', this.onLoad);
        document.removeEventListener('visibilitychange', this.onVisibility);
    }

    play({ params: { id } }) {
        if (player.id === id) {
            player.toggle();
        } else {
            player.load(id, { play: true });
        }
    }

    schedule() {
        clearTimeout(this.timer);
        if (this.element.querySelector('[data-in-progress]')) {
            this.timer = setTimeout(() => this.element.reload(), POLL_MS);
        } else if (document.visibilityState === 'visible') {
            this.timer = setTimeout(() => this.element.reload(), IDLE_POLL_MS);
        }
    }

    onPlayer() {
        const snapshot = player.snapshot();
        const active = ['playing', 'buffering'].includes(snapshot.state) || (snapshot.state === 'loading' && player.wantsToPlay);
        for (const entry of this.element.querySelectorAll('[data-article-id]')) {
            const current = entry.dataset.articleId === snapshot.id;
            entry.classList.toggle('is-current', current);
            const button = entry.querySelector('.entry__play');
            if (!button) {
                continue;
            }
            const playing = current && active;
            button.classList.toggle('is-playing', playing);
            const title = button.dataset.libraryTitleParam ?? '';
            button.setAttribute('aria-label', (playing ? this.labelsValue.pause : this.labelsValue.play).replace('%title%', title));
        }
    }
}

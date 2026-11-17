import { Controller } from '@hotwired/stimulus';

/*
 * Copies a snippet (MCP command, feed address, bookmarklet) and says so for 2 seconds.
 */
export default class extends Controller {
    static values = { text: String, copied: String };

    static targets = ['text'];

    connect() {
        this.label = this.hasTextTarget ? this.textTarget.textContent : '';
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    async copy() {
        try {
            await navigator.clipboard.writeText(this.textValue);
        } catch {
            // Without the Clipboard API (plain http on another host): the old way.
            const area = document.createElement('textarea');
            area.value = this.textValue;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.append(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }
        this.element.classList.add('is-copied');
        if (this.hasTextTarget) {
            this.textTarget.textContent = this.copiedValue;
        }
        clearTimeout(this.timer);
        this.timer = setTimeout(() => {
            this.element.classList.remove('is-copied');
            if (this.hasTextTarget) {
                this.textTarget.textContent = this.label;
            }
        }, 2000);
    }
}

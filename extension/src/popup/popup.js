/* Crieur extension: the toolbar popup. It sends the article of the current tab to Crieur. */
(async function () {
    'use strict';
    const ext = globalThis.browser ?? globalThis.chrome;
    const { normalizeServerUrl } = globalThis.CrieurShared;
    const form = document.getElementById('send');
    const button = document.getElementById('send-button');
    const label = document.getElementById('send-label');
    const result = document.getElementById('result');

    // ?tab=<id> shows another tab than the active one: for the end-to-end test and the store screenshots.
    const requested = Number(new URLSearchParams(location.search).get('tab'));
    const tab = requested > 0 ? await ext.tabs.get(requested) : (await ext.tabs.query({ active: true, currentWindow: true }))[0];
    const stored = await ext.storage.local.get(['serverUrl', 'language', 'voice']);
    const server = normalizeServerUrl(stored.serverUrl);

    document.getElementById('page-title').textContent = (tab && tab.title) || t('popupNoTitle');
    try {
        document.getElementById('page-host').textContent = tab && tab.url ? new URL(tab.url).hostname : '';
    } catch (error) {
        document.getElementById('page-host').textContent = '';
    }
    document.getElementById('server-host').textContent = server || t('popupNoServer');
    for (const [name, value] of [['voice', stored.voice], ['language', stored.language]]) {
        const input = value && form.querySelector(`input[name="${name}"][value="${value}"]`);
        if (input) {
            input.checked = true;
        }
    }

    document.getElementById('open-options').addEventListener('click', (event) => {
        event.preventDefault();
        ext.runtime.openOptionsPage();
        window.close();
    });

    form.addEventListener('change', () => {
        ext.storage.local.set({ voice: form.voice.value, language: form.language.value });
    });

    function show(kind, title, text, action) {
        result.replaceChildren();
        const notice = document.createElement('p');
        notice.className = 'notice notice--' + kind;
        notice.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        const strong = document.createElement('strong');
        strong.textContent = title;
        notice.append(strong, document.createTextNode(text || ''));
        result.append(notice);
        if (action) {
            result.append(action);
        }
        result.hidden = false;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!tab || tab.id === undefined) {
            return;
        }
        button.disabled = true;
        label.textContent = t('popupReading');
        let answer = null;
        try {
            answer = await ext.runtime.sendMessage({
                type: 'crieur:send',
                tabId: tab.id,
                choices: { voice: form.voice.value, language: form.language.value },
            });
        } catch (error) {
            // No answer from the background script: told below as a server error.
        } finally {
            button.disabled = false;
            label.textContent = t('popupRead');
        }

        if (answer && answer.ok) {
            const open = document.createElement('a');
            open.className = 'button button--ghost';
            open.href = answer.listenUrl;
            open.textContent = t('popupOpen');
            open.addEventListener('click', (event) => {
                event.preventDefault();
                ext.tabs.create({ url: answer.listenUrl });
                window.close();
            });
            show('ok', t('popupSent'), answer.title, open);
            form.hidden = true;

            return;
        }

        const key = (answer && answer.error) || 'errorServer';
        let action = null;
        if (key === 'errorSetup' || key === 'errorPermission' || key === 'errorToken' || key === 'errorUnreachable') {
            action = document.createElement('button');
            action.type = 'button';
            action.className = 'button button--ghost';
            action.textContent = t('popupOptions');
            action.addEventListener('click', () => {
                ext.runtime.openOptionsPage();
                window.close();
            });
        }
        show('error', t(key), answer && answer.message ? answer.message : '', action);
    });
})();

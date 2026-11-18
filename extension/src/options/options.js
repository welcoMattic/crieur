/* Crieur extension: the options page. Saves the server, asks for the right to reach it, tests it. */
(async function () {
    'use strict';
    const ext = globalThis.browser ?? globalThis.chrome;
    const { DEFAULT_SERVER, normalizeServerUrl, originPattern } = globalThis.CrieurShared;
    const form = document.getElementById('options');
    const status = document.getElementById('status');

    const stored = await ext.storage.local.get(['serverUrl', 'token']);
    form.server.value = stored.serverUrl || DEFAULT_SERVER;
    form.token.value = stored.token || '';

    function show(kind, title, text) {
        status.replaceChildren();
        const notice = document.createElement('p');
        notice.className = 'notice notice--' + kind;
        const strong = document.createElement('strong');
        strong.textContent = title;
        notice.append(strong, document.createTextNode(text || ''));
        status.append(notice);
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const server = normalizeServerUrl(form.server.value);
        if (!server) {
            show('error', t('optionsInvalidServer'));

            return;
        }
        // Asked from the click: browsers grant host permissions only on a user gesture.
        const granted = await ext.permissions.request({ origins: [originPattern(server)] });
        if (!granted) {
            show('error', t('errorPermission'));

            return;
        }
        const token = form.token.value.trim();
        await ext.storage.local.set({ serverUrl: server, token });
        form.server.value = server;

        try {
            const headers = { Accept: 'application/json' };
            if (token) {
                headers.Authorization = 'Bearer ' + token;
            }
            const response = await fetch(server + '/api/ping', { headers, credentials: 'omit' });
            const answer = await response.json().catch(() => ({}));
            if (response.status === 401) {
                show('error', t('errorToken'));
            } else if (response.ok && answer.name === 'Crieur') {
                show('ok', t('optionsConnected'), t('optionsConnectedDetail', [answer.voiceEngine || '?', answer.editor || t('optionsRules')]));
            } else {
                show('error', t('errorServer'));
            }
        } catch (error) {
            show('error', t('errorUnreachable'));
        }
    });
})();

/*
 * Crieur browser extension: background (service worker in Chrome, event page in Firefox).
 *
 * On a click (toolbar popup or page context menu), it injects Defuddle in the page the reader
 * sees, takes the article out of it, and sends it to the reader's own Crieur server. Nothing is
 * sent anywhere else, no cookie goes with it, and nothing runs on pages the reader did not ask for.
 */
'use strict';

if (typeof globalThis.CrieurShared === 'undefined' && typeof importScripts === 'function') {
    importScripts('lib/shared.js');
}

const ext = globalThis.browser ?? globalThis.chrome;
const { buildPayload, errorKey, normalizeServerUrl, originPattern, MAX_CONTENT } = globalThis.CrieurShared;

async function readSettings() {
    const stored = await ext.storage.local.get(['serverUrl', 'token', 'language', 'voice']);

    return {
        serverUrl: normalizeServerUrl(stored.serverUrl),
        token: typeof stored.token === 'string' ? stored.token.trim() : '',
        language: stored.language || 'auto',
        voice: stored.voice || 'female',
    };
}

/**
 * Reads the article of a tab and sends it to Crieur.
 *
 * @return {Promise<{ok: true, title: string, listenUrl: string}|{ok: false, error: string, message?: string}>}
 */
async function sendTab(tabId, choices) {
    const settings = await readSettings();
    if (!settings.serverUrl) {
        return { ok: false, error: 'errorSetup' };
    }
    if (!(await ext.permissions.contains({ origins: [originPattern(settings.serverUrl)] }))) {
        return { ok: false, error: 'errorPermission' };
    }

    let extracted;
    try {
        const results = await ext.scripting.executeScript({
            target: { tabId },
            files: ['vendor/defuddle.js', 'content/extract.js'],
        });
        extracted = results && results[0] ? results[0].result : null;
    } catch (error) {
        // Browser pages, store pages, PDF viewers: the browser does not let extensions read them.
        return { ok: false, error: 'errorPage' };
    }
    if (!extracted || extracted.error) {
        return { ok: false, error: 'errorNoArticle' };
    }
    if (extracted.content.length > MAX_CONTENT) {
        return { ok: false, error: 'errorTooLong' };
    }

    const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
    if (settings.token) {
        headers.Authorization = 'Bearer ' + settings.token;
    }

    let response;
    try {
        response = await fetch(settings.serverUrl + '/api/articles', {
            method: 'POST',
            headers,
            credentials: 'omit',
            body: JSON.stringify(buildPayload(extracted, { language: choices?.language ?? settings.language, voice: choices?.voice ?? settings.voice })),
        });
    } catch (error) {
        return { ok: false, error: 'errorUnreachable' };
    }

    let answer = {};
    try {
        answer = await response.json();
    } catch (error) {
        answer = {};
    }
    if (response.status !== 201) {
        return { ok: false, error: errorKey(response.status, answer.error), message: typeof answer.message === 'string' ? answer.message : undefined };
    }

    return { ok: true, title: answer.title || extracted.title, listenUrl: answer.listenUrl };
}

async function flagTab(tabId, ok) {
    await ext.action.setBadgeBackgroundColor({ tabId, color: ok ? '#1c7445' : '#b42318' });
    await ext.action.setBadgeText({ tabId, text: ok ? 'OK' : '!' });
    setTimeout(() => ext.action.setBadgeText({ tabId, text: '' }).catch(() => {}), 6000);
}

ext.runtime.onInstalled.addListener(() => {
    ext.contextMenus.create({ id: 'crieur-read', title: ext.i18n.getMessage('menuRead'), contexts: ['page'] });
});

ext.contextMenus.onClicked.addListener(async (info, tab) => {
    if (info.menuItemId !== 'crieur-read' || !tab || tab.id === undefined) {
        return;
    }
    const result = await sendTab(tab.id);
    await flagTab(tab.id, result.ok);
    if (!result.ok && (result.error === 'errorSetup' || result.error === 'errorPermission')) {
        await ext.runtime.openOptionsPage();
    }
});

ext.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (message && message.type === 'crieur:send' && typeof message.tabId === 'number') {
        sendTab(message.tabId, message.choices).then(sendResponse, () => sendResponse({ ok: false, error: 'errorServer' }));

        return true;
    }

    return false;
});

// For the end-to-end test, which drives the background directly.
globalThis.crieurSend = sendTab;

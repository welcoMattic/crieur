/*
 * Crieur browser extension: code shared by the background, the popup, the options page and the
 * script injected in the page. No dependency, no build step: the stores review this file as is.
 */
(function (root) {
    'use strict';

    const DEFAULT_SERVER = 'http://127.0.0.1:8000';
    const MAX_CONTENT = 3000000;

    /**
     * The address of a Crieur server, without trailing slash, or null when it is not an http(s) URL.
     */
    function normalizeServerUrl(value) {
        const text = String(value || '').trim();
        if (text === '') {
            return null;
        }
        let url;
        try {
            url = new URL(/^[a-z][a-z0-9+.-]*:\/\//i.test(text) ? text : 'http://' + text);
        } catch (error) {
            return null;
        }
        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            return null;
        }

        return (url.origin + url.pathname).replace(/\/+$/, '');
    }

    /**
     * The match pattern of the host permission the server needs, e.g. "https://crieur.example/*".
     */
    function originPattern(serverUrl) {
        const url = new URL(serverUrl);

        return url.protocol + '//' + url.hostname + '/*';
    }

    /**
     * The title without the name of the site that some pages append to it ("Town crier - Wikipedia",
     * "Title | Site", any dash), when that name is the site name or part of the address.
     */
    function cleanTitle(title, siteName, url) {
        const text = String(title || '').trim();
        const match = text.match(/^(.+?)\s+[-\u2013\u2014|\u00b7:]\s+([^-\u2013\u2014|\u00b7:]{2,60})$/);
        if (!match) {
            return text;
        }
        const plain = (value) => String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9]/g, '');
        const suffix = plain(match[2]);
        // The site name, the host ("example.org") or one of its labels but the last ("wikipedia").
        const names = [plain(siteName)];
        try {
            const labels = new URL(url).hostname.replace(/^www\./, '').split('.');
            names.push(plain(labels.join('.')), ...labels.slice(0, -1).map(plain));
        } catch (error) {
            // No address: the site name alone decides.
        }

        return suffix !== '' && names.includes(suffix) ? match[1].trim() : text;
    }

    /**
     * The address with its path, query and fragment strictly percent-encoded: browsers leave
     * characters such as "|", "{" or "^" as they are, and the server refuses such an address.
     * Null when it is not an http(s) URL.
     */
    function encodeUrl(value) {
        let url;
        try {
            url = new URL(String(value || ''));
        } catch (error) {
            return null;
        }
        if (url.protocol !== 'http:' && url.protocol !== 'https:') {
            return null;
        }
        const strict = (text) => text.replace(/%(?![0-9A-Fa-f]{2})|[^A-Za-z0-9\-._~:/?@!$&'()*+,;=%]/gu, (c) => encodeURIComponent(c));

        return url.origin + strict(url.pathname + url.search) + (url.hash === '' ? '' : '#' + strict(url.hash.slice(1)));
    }

    // The longest values the server takes (src/Api/BrowserArticle.php).
    const LIMITS = { siteName: 255, byline: 255, lang: 35, excerpt: 2000, image: 2048, publishedTime: 64 };

    /**
     * The JSON body of POST /api/articles, from what the page script extracted.
     */
    function buildPayload(extracted, choices) {
        const payload = {
            url: encodeUrl(extracted.url) || extracted.url,
            title: cleanTitle(extracted.title, extracted.siteName, extracted.url).slice(0, 512),
            content: extracted.content,
            language: ['auto', 'fr', 'en'].includes(choices && choices.language) ? choices.language : 'auto',
            voice: ['female', 'male'].includes(choices && choices.voice) ? choices.voice : 'female',
        };
        for (const [key, max] of Object.entries(LIMITS)) {
            if (typeof extracted[key] === 'string' && extracted[key].trim() !== '') {
                payload[key] = extracted[key].trim().slice(0, max).trim();
            }
        }
        if (payload.image) {
            // An address cut short is a wrong address: it is left out instead.
            const image = encodeUrl(extracted.image.trim());
            if (image && image.length <= LIMITS.image) {
                payload.image = image;
            } else {
                delete payload.image;
            }
        }

        return payload;
    }

    /**
     * A short message key for an answer of the server, or for a failure before it.
     */
    function errorKey(status, code) {
        if (status === 401) {
            return 'errorToken';
        }
        if (status === 403) {
            return 'errorRefused';
        }
        if (code === 'extraction.no_text') {
            return 'errorNoText';
        }
        if (code === 'extraction.unsupported_language') {
            return 'errorLanguage';
        }
        if (status === 413) {
            return 'errorTooLong';
        }

        return 'errorServer';
    }

    const api = { DEFAULT_SERVER, MAX_CONTENT, normalizeServerUrl, originPattern, cleanTitle, encodeUrl, buildPayload, errorKey };
    root.CrieurShared = api;
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
})(typeof globalThis !== 'undefined' ? globalThis : this);

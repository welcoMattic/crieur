/* Crieur extension: translations of the popup and the options page (data-i18n attributes). */
(function () {
    'use strict';
    const ext = globalThis.browser ?? globalThis.chrome;
    globalThis.t = (key, substitutions) => ext.i18n.getMessage(key, substitutions) || key;
    for (const element of document.querySelectorAll('[data-i18n]')) {
        element.textContent = globalThis.t(element.dataset.i18n);
    }
    for (const element of document.querySelectorAll('[data-i18n-placeholder]')) {
        element.placeholder = globalThis.t(element.dataset.i18nPlaceholder);
    }
    for (const element of document.querySelectorAll('[data-i18n-label]')) {
        element.setAttribute('aria-label', globalThis.t(element.dataset.i18nLabel));
    }
    document.documentElement.lang = ext.i18n.getUILanguage().slice(0, 2) === 'fr' ? 'fr' : 'en';
})();

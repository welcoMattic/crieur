/*
 * Injected in the page the reader asked to listen to, after vendor/defuddle.js: Defuddle by
 * Steph Ango, the extraction engine of Obsidian Web Clipper (and of the macOS version of Crieur).
 * It reads the page as the browser shows it, with the reader's own access, and works on its own
 * copy of the document: the page itself is never modified. The last expression is the result.
 */
(function () {
    'use strict';

    let result = null;
    try {
        result = new Defuddle(document, { url: location.href }).parse();
    } catch (error) {
        return { error: 'extract' };
    }
    if (!result || !result.content || !result.content.trim()) {
        return { error: 'noArticle' };
    }

    const canonical = document.querySelector('link[rel="canonical"]');
    const canonicalUrl = canonical ? canonical.href : '';

    return {
        url: /^https?:\/\//i.test(canonicalUrl) ? canonicalUrl : location.href,
        title: result.title || document.title,
        content: result.content,
        siteName: result.site || '',
        byline: result.author || '',
        lang: result.language || document.documentElement.lang || '',
        excerpt: result.description || '',
        publishedTime: result.published || '',
        image: result.image || '',
    };
})();

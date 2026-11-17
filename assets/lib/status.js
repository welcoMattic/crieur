/*
 * The status of an article (GET /articles/{id}/status), polled once per article whatever the number
 * of subscribers: the player and the read-along page share the same request. Polling goes on every
 * second while the article is being prepared or some chunk has no audio yet, then stops.
 */

const INTERVAL_MS = 1000;
const RETRY_MS = 3000;
const watches = new Map();
const forcedUntil = new Map(); // url => timestamp: keep polling after a change sent from a form

export function watch(url, callback) {
    let entry = watches.get(url);
    if (!entry) {
        entry = { url, callbacks: new Set(), timer: null, last: null, inflight: false, again: false };
        watches.set(url, entry);
    }
    entry.callbacks.add(callback);
    if (entry.last) {
        queueMicrotask(() => entry.callbacks.has(callback) && callback(entry.last));
    }
    poll(entry);

    return () => {
        entry.callbacks.delete(callback);
        if (entry.callbacks.size === 0) {
            clearTimeout(entry.timer);
            watches.delete(url);
        }
    };
}

/**
 * Asks for the status now, after a change made elsewhere (a form, another tab). With a duration,
 * polling goes on that long even if the article looks idle: a retry or another language only
 * queues a message, and the worker takes it a moment later.
 */
export function refresh(url, keepPollingMs = 0) {
    if (keepPollingMs > 0) {
        forcedUntil.set(url, Math.max(forcedUntil.get(url) ?? 0, Date.now() + keepPollingMs));
    }
    const entry = watches.get(url);
    if (entry) {
        poll(entry);
    }
}

async function poll(entry) {
    clearTimeout(entry.timer);
    entry.timer = null;
    if (entry.inflight) {
        entry.again = true;
        return;
    }

    entry.inflight = true;
    let data = null;
    let failed = false;
    try {
        const response = await fetch(entry.url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
        if (response.status === 404) {
            data = { gone: true };
        } else if (response.ok) {
            data = await response.json();
        } else {
            failed = true;
        }
    } catch {
        failed = true;
    }
    entry.inflight = false;

    if (watches.get(entry.url) !== entry) {
        return;
    }
    if (data) {
        entry.last = data;
        for (const callback of [...entry.callbacks]) {
            callback(data);
        }
    }
    if (entry.again) {
        entry.again = false;
        poll(entry);
    } else if (failed || needsPolling(data) || (forcedUntil.get(entry.url) ?? 0) > Date.now()) {
        entry.timer = setTimeout(() => poll(entry), failed ? RETRY_MS : INTERVAL_MS);
    }
}

function needsPolling(data) {
    if (!data || data.gone || data.status === 'failed') {
        return false;
    }

    return data.inProgress || data.segments.some((segment) => !segment.ready);
}

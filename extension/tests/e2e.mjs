// End-to-end test of the extension in Chromium (headless, muted), against its own Crieur
// instance: a separate database, audio folder and port, no queue worker. It never touches the
// Crieur you use every day.
//
//   npm run e2e                     (CHROMIUM_PATH=/path/to/chromium to use another browser)
import { spawn } from 'node:child_process';
import { execFileSync } from 'node:child_process';
import http from 'node:http';
import { mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const app = join(root, '..');
const run = `e2e-${Date.now()}`;
const CRIEUR = 'http://127.0.0.1:8099';
const PAGE = 'http://127.0.0.1:8098/essay';
const TOKEN = 'e2e-token-0123456789';
const env = {
    ...process.env,
    APP_ENV: 'dev',
    DATABASE_URL: `sqlite:///${app}/var/e2e/${run}.db`,
    CRIEUR_AUDIO_DIR: `${app}/var/e2e/${run}-audio`,
    MESSENGER_TRANSPORT_DSN: 'in-memory://',
    CRIEUR_EDITOR: 'off',
    CRIEUR_VOICE_ENGINE: 'phonon',
    CRIEUR_API_TOKEN: TOKEN,
    DEFAULT_URI: CRIEUR,
};

const children = [];
let browser;
let page;
try {
    execFileSync('php', ['bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--quiet'], { cwd: app, env });
    // variables_order=EGPCS: without E, the built-in server hides the environment from PHP and
    // .env.local would win (the database of the everyday Crieur).
    children.push(spawn('php', ['-d', 'variables_order=EGPCS', '-S', '127.0.0.1:8099', '-t', 'public'], { cwd: app, env, stdio: 'ignore' }));

    // The article page, as a site would serve it.
    const fixture = readFileSync(join(app, 'tests/Fixtures/extraction/article.html'));
    const site = http.createServer((request, response) => response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(fixture));
    await new Promise((resolve) => site.listen(8098, '127.0.0.1', resolve));
    children.push({ kill: () => site.close() });
    await waitFor(`${CRIEUR}/api/ping`);
    // Never go on against another Crieur than the one of this test.
    assert.equal((await fetch(`${CRIEUR}/api/ping`)).status, 401, 'the test instance must require its token');

    browser = await chromium.launchPersistentContext(mkdtempSync(join(tmpdir(), 'crieur-e2e-')), {
        executablePath: process.env.CHROMIUM_PATH || undefined,
        headless: true,
        args: ['--mute-audio', `--disable-extensions-except=${join(root, 'dist/chrome-e2e')}`, `--load-extension=${join(root, 'dist/chrome-e2e')}`],
    });
    const worker = browser.serviceWorkers()[0] ?? (await browser.waitForEvent('serviceworker'));
    page = await browser.newPage();
    await page.goto(PAGE);
    const tabId = await worker.evaluate(async (url) => (await chrome.tabs.query({ url: url + '*' }))[0].id, PAGE);

    // A wrong token is refused, with a message for the reader.
    await worker.evaluate((token) => chrome.storage.local.set({ serverUrl: 'http://127.0.0.1:8099/', token }), 'wrong');
    assert.deepEqual(await worker.evaluate((id) => globalThis.crieurSend(id), tabId), { ok: false, error: 'errorToken', message: 'This Crieur server needs its API token (CRIEUR_API_TOKEN) in the options of the extension.' });

    // The right one: the article read in the page is in Crieur, with the chosen voice.
    await worker.evaluate((token) => chrome.storage.local.set({ token }), TOKEN);
    const sent = await worker.evaluate((id) => globalThis.crieurSend(id, { voice: 'male', language: 'auto' }), tabId);
    assert.equal(sent.ok, true, JSON.stringify(sent));
    assert.equal(sent.title, 'Why we read slower than we listen');
    assert.match(sent.listenUrl, /^http:\/\/127\.0\.0\.1:8099\/articles\/[0-9a-f-]{36}$/);

    const status = await (await fetch(sent.listenUrl + '/status')).json();
    assert.equal(status.title, 'Why we read slower than we listen');
    assert.equal(status.voice, 'Preston');
    assert.ok(status.segments.length > 5, 'the article has its chunks');
    assert.ok(status.segments.some((segment) => segment.chars > 0));

    // A page the browser does not let extensions read.
    const settingsTab = await worker.evaluate(async () => (await chrome.tabs.create({ url: 'chrome://version' })).id);
    assert.equal((await worker.evaluate((id) => globalThis.crieurSend(id), settingsTab)).error, 'errorPage');

    console.log('ok: the extension reads the page in the browser and Crieur prepares it, token checked');
} finally {
    await browser?.close();
    for (const child of children) {
        child.kill();
    }
}

async function waitFor(url) {
    for (let attempt = 0; attempt < 50; attempt++) {
        try {
            await fetch(url);

            return;
        } catch {
            await new Promise((resolve) => setTimeout(resolve, 200));
        }
    }
    throw new Error(`${url} does not answer`);
}

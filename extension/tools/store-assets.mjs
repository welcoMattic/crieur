// Store images of the extension, from the real interface: headless Chromium (muted) with the
// extension loaded, against a Crieur instance of its own (port 8097, a copy of the demo library,
// Phonon through Ollama). Writes extension/store/*.png.
//
//   node build.mjs --e2e && node tools/store-assets.mjs
//   (CHROMIUM_PATH=/path/to/chromium, DEMO_DB=/path/to/a/library.db to start from another library,
//    STORE_LANG=fr for the French screenshots)
import { spawn, execFileSync } from 'node:child_process';
import { copyFileSync, cpSync, mkdirSync, mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const app = join(root, '..');
const out = join(root, 'store');
const lang = process.env.STORE_LANG === 'fr' ? 'fr' : 'en';
const run = `store-${lang}-${Date.now()}`;
const CRIEUR = 'http://127.0.0.1:8097';
const ARTICLE = process.env.STORE_LANG === 'fr' ? 'https://fr.wikipedia.org/wiki/Crieur_public' : 'https://en.wikipedia.org/wiki/Town_crier';
const db = join(app, 'var/e2e', `${run}.db`);
mkdirSync(dirname(db), { recursive: true });
if (process.env.DEMO_DB) {
    copyFileSync(process.env.DEMO_DB, db);
}
const env = {
    ...process.env,
    APP_ENV: 'dev',
    DATABASE_URL: `sqlite:///${db}`,
    CRIEUR_AUDIO_DIR: `${app}/var/e2e/${run}-audio`,
    MESSENGER_TRANSPORT_DSN: 'doctrine://default?auto_setup=1',
    CRIEUR_EDITOR: 'off',
    CRIEUR_VOICE_ENGINE: 'phonon',
    CRIEUR_API_TOKEN: '',
    DEFAULT_URI: CRIEUR,
};

const children = [];
let browser;
try {
    execFileSync('php', ['bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--quiet'], { cwd: app, env });
    children.push(spawn('php', ['-d', 'variables_order=EGPCS', '-S', '127.0.0.1:8097', '-t', 'public'], { cwd: app, env, stdio: 'ignore' }));
    children.push(spawn('php', ['bin/console', 'messenger:consume', 'extract', 'synthesize', '--time-limit=600'], { cwd: app, env, stdio: 'ignore' }));
    await waitFor(`${CRIEUR}/api/ping`);

    // chrome.i18n follows the interface language of the browser (on macOS, that of the system, --lang
    // is ignored): a copy of the extension with the chosen language only renders in that language.
    const extension = join(mkdtempSync(join(tmpdir(), 'crieur-store-extension-')), 'extension');
    cpSync(join(root, 'dist/chrome-e2e'), extension, { recursive: true, filter: (path) => !new RegExp(`_locales/(?!${lang}$)[^/]+$`).test(path) });
    const manifest = JSON.parse(readFileSync(join(extension, 'manifest.json'), 'utf8'));
    writeFileSync(join(extension, 'manifest.json'), JSON.stringify({ ...manifest, default_locale: lang }));
    browser = await chromium.launchPersistentContext(mkdtempSync(join(tmpdir(), 'crieur-store-')), {
        executablePath: process.env.CHROMIUM_PATH || undefined,
        headless: true,
        viewport: { width: 1280, height: 800 },
        locale: lang === 'fr' ? 'fr-FR' : 'en-US',
        colorScheme: 'light',
        args: ['--mute-audio', `--lang=${lang}`, `--disable-extensions-except=${extension}`, `--load-extension=${extension}`],
    });
    await browser.addCookies([{ name: 'crieur_locale', value: lang, url: CRIEUR }]);
    const worker = browser.serviceWorkers()[0] ?? (await browser.waitForEvent('serviceworker'));
    const id = new URL(worker.url()).host;
    await worker.evaluate((server) => chrome.storage.local.set({ serverUrl: server, token: '' }), CRIEUR);

    const page = await browser.newPage();
    await page.goto(ARTICLE, { waitUntil: 'networkidle' });
    await page.bringToFront();
    const tabId = await worker.evaluate(async () => (await chrome.tabs.query({ active: true, lastFocusedWindow: true }))[0].id);
    const article = await page.screenshot();

    // The popup, rendered by the extension itself for the article tab.
    const popup = await browser.newPage();
    await popup.setViewportSize({ width: 340, height: 440 });
    await popup.goto(`chrome-extension://${id}/popup/popup.html?tab=${tabId}`);
    await popup.waitForTimeout(300);
    const popupBefore = await popup.locator('main').screenshot();
    await popup.locator('#send-button').click();
    await popup.waitForSelector('#result:not([hidden])', { timeout: 30000 });
    const popupAfter = await popup.locator('main').screenshot();
    const listenUrl = await popup.locator('#result a').getAttribute('href');

    const compose = await browser.newPage();
    await compose.setViewportSize({ width: 1280, height: 800 });
    for (const [name, shot] of [['1-popup', popupBefore], ['2-sent', popupAfter]]) {
        await compose.setContent(`<!doctype html><html><body style="margin:0;width:1280px;height:800px;overflow:hidden;position:relative;background:#fff">
            <img src="data:image/png;base64,${article.toString('base64')}" style="position:absolute;inset:0;width:1280px;height:800px">
            <img src="data:image/png;base64,${shot.toString('base64')}" style="position:absolute;top:8px;right:16px;width:340px;border-radius:12px;box-shadow:0 2px 6px rgb(16 18 24/.12),0 18px 48px rgb(16 18 24/.24)">
            </body></html>`);
        await compose.screenshot({ path: join(out, `screenshot-${lang}-${name}.png`) });
    }

    // Crieur reads it: the article page while the voice works, then the library with its badge.
    await waitForSegments(listenUrl, 30);
    const crieur = await browser.newPage();
    await crieur.addInitScript(() => document.addEventListener('DOMContentLoaded', () => {
        const style = document.createElement('style');
        style.textContent = '.sf-toolbar,.sf-minitoolbar{display:none!important}';
        document.head.appendChild(style);
    }));
    await crieur.goto(listenUrl);
    await crieur.waitForTimeout(600);
    await crieur.screenshot({ path: join(out, `screenshot-${lang}-3-article.png`) });
    await crieur.goto(`${CRIEUR}/`);
    await crieur.waitForTimeout(600);
    await crieur.screenshot({ path: join(out, `screenshot-${lang}-4-library.png`) });

    const options = await browser.newPage();
    await options.goto(`chrome-extension://${id}/options/options.html`);
    await options.locator('button[type="submit"]').click().catch(() => {});
    await options.waitForTimeout(800);
    await options.screenshot({ path: join(out, `screenshot-${lang}-5-options.png`) });

    // The small promo tile, 440 x 280.
    const tile = await browser.newPage();
    await tile.setViewportSize({ width: 440, height: 280 });
    await tile.setContent(readFileSync(join(root, 'tools/promo-tile.html'), 'utf8'));
    await tile.screenshot({ path: join(out, 'promo-small-440x280.png') });

    console.log(`store images in ${out}`);
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

async function waitForSegments(listenUrl, count) {
    for (let attempt = 0; attempt < 120; attempt++) {
        const status = await (await fetch(listenUrl + '/status')).json();
        if (status.segments.filter((segment) => segment.ready).length >= count || status.status === 'ready') {
            return;
        }
        await new Promise((resolve) => setTimeout(resolve, 1000));
    }
}

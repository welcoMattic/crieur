// End-to-end test of the Firefox package (dist/crieur-firefox-<version>.zip, as uploaded to AMO) in
// headless Firefox, muted, through geckodriver, against its own Crieur instance (separate database,
// audio folder and port, no queue worker). It drives the options page like a reader: the address
// of the server, the token, "Save and test" (the permission request needs that click), then sends
// an article through the background.
//
//   npm run e2e:firefox     (FIREFOX_PATH=/path/to/firefox, GECKODRIVER=/path/to/geckodriver)
//
// The server is reached as http://crieur.test (mapped to 127.0.0.1 inside this Firefox only): an
// address that is neither https nor loopback, like a Crieur on a home server.
import { spawn, execFileSync } from 'node:child_process';
import http from 'node:http';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { randomUUID } from 'node:crypto';
import assert from 'node:assert/strict';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const app = join(root, '..');
const run = `e2e-firefox-${Date.now()}`;
const HOST = process.env.E2E_HOST || 'crieur.test';
const CRIEUR = `http://${HOST}:8099`;
const PAGE = `http://${HOST}:8098/essay`;
const TOKEN = 'e2e-token-0123456789';
const GECKO_ID = '{6f1c4d1e-3b7a-4a49-9c55-2f6e8f0b9d21}';
const UUID = randomUUID();
const ELEMENT = 'element-6066-11e4-a52e-4f735466cecf';
const version = JSON.parse(readFileSync(join(root, 'src/manifest.json'), 'utf8')).version;
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
let driver;
let session;
try {
    execFileSync('node', ['build.mjs'], { cwd: root, stdio: 'ignore' });
    execFileSync('php', ['bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--quiet'], { cwd: app, env });
    // variables_order=EGPCS: without E, the built-in server hides the environment from PHP and
    // .env.local would win (the database of the everyday Crieur).
    children.push(spawn('php', ['-d', 'variables_order=EGPCS', '-S', '127.0.0.1:8099', '-t', 'public'], { cwd: app, env, stdio: 'ignore' }));
    const fixture = readFileSync(join(app, 'tests/Fixtures/extraction/article.html'));
    const site = http.createServer((request, response) => response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(fixture));
    await new Promise((resolve) => site.listen(8098, '127.0.0.1', resolve));
    children.push({ kill: () => site.close() });
    await waitFor('http://127.0.0.1:8099/api/ping');
    // Never go on against another Crieur than the one of this test.
    assert.equal((await fetch('http://127.0.0.1:8099/api/ping')).status, 401, 'the test instance must require its token');

    // System access: WebDriver may not open extension pages, the browser chrome may.
    driver = spawn(process.env.GECKODRIVER || 'geckodriver', ['--port', '4446', '--allow-system-access'], { stdio: 'ignore' });
    children.push(driver);
    await waitFor('http://127.0.0.1:4446/status');
    session = (await webdriver('POST', '/session', {
        capabilities: {
            alwaysMatch: {
                browserName: 'firefox',
                'moz:firefoxOptions': {
                    binary: process.env.FIREFOX_PATH || '/Applications/Firefox.app/Contents/MacOS/firefox',
                    // geckodriver starts Firefox on a new temporary profile.
                    args: ['-headless'],
                    prefs: {
                        'network.dns.localDomains': 'crieur.test',
                        'extensions.webextensions.uuids': JSON.stringify({ [GECKO_ID]: UUID }),
                        // Grants the optional permission without the prompt a test cannot answer.
                        'extensions.webextOptionalPermissionPrompts': false,
                        'media.volume_scale': '0.0',
                    },
                },
            },
        },
    })).sessionId;
    await webdriver('POST', `/session/${session}/moz/addon/install`, { path: join(root, `dist/crieur-firefox-${version}.zip`), temporary: true });

    await webdriver('POST', `/session/${session}/url`, { url: PAGE });
    const article = await webdriver('GET', `/session/${session}/window`);
    const before = await webdriver('GET', `/session/${session}/window/handles`);
    await webdriver('POST', `/session/${session}/moz/context`, { context: 'chrome' });
    await webdriver('POST', `/session/${session}/execute/sync`, {
        script: `gBrowser.selectedTab = gBrowser.addTab(arguments[0], { triggeringPrincipal: Services.scriptSecurityManager.getSystemPrincipal() });`,
        args: [`moz-extension://${UUID}/options/options.html`],
    });
    await webdriver('POST', `/session/${session}/moz/context`, { context: 'content' });
    const options = (await webdriver('GET', `/session/${session}/window/handles`)).find((handle) => !before.includes(handle));
    await webdriver('POST', `/session/${session}/window`, { handle: options });
    await waitForText('button[type="submit"]');

    // The options page, as a reader fills it.
    const server = await find('#server');
    await webdriver('POST', `/session/${session}/element/${server}/clear`, {});
    await webdriver('POST', `/session/${session}/element/${server}/value`, { text: CRIEUR });
    await webdriver('POST', `/session/${session}/element/${await find('#token')}/value`, { text: TOKEN });
    await webdriver('POST', `/session/${session}/element/${await find('button[type="submit"]')}/click`, {});
    const status = await waitForText('#status');
    assert.match(status, /Connected to Crieur/, `options page: ${status}`);

    // The article of the other tab, sent through the background as the popup does.
    const sent = await webdriver('POST', `/session/${session}/execute/async`, {
        script: `const done = arguments[arguments.length - 1];
            browser.tabs.query({ url: arguments[0] })
                .then(([tab]) => browser.runtime.sendMessage({ type: 'crieur:send', tabId: tab.id, choices: { voice: 'male', language: 'auto' } }))
                .then(done, (error) => done({ ok: false, error: String(error) }));`,
        // A match pattern has no port: it matches any port of the host.
        args: [`http://${HOST}/essay*`],
    });
    assert.equal(sent.ok, true, JSON.stringify(sent));
    assert.equal(sent.title, 'Why we read slower than we listen');
    assert.match(sent.listenUrl, /^http:\/\/[^/]+:8099\/articles\/[0-9a-f-]{36}$/);

    const answer = await (await fetch(sent.listenUrl.replace(HOST, '127.0.0.1') + '/status')).json();
    assert.equal(answer.voice, 'Preston');
    assert.ok(answer.segments.length > 5, 'the article has its chunks');
    assert.ok(article, 'the article tab is still there');

    console.log('ok: Firefox package, options page (permission, http server), article sent and prepared');
} finally {
    if (session) {
        await webdriver('DELETE', `/session/${session}`).catch(() => {});
    }
    for (const child of children) {
        child.kill();
    }
}

async function webdriver(method, path, body) {
    const response = await fetch('http://127.0.0.1:4446' + path, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const answer = await response.json();
    if (!response.ok) {
        throw new Error(`${method} ${path}: ${JSON.stringify(answer.value)}`);
    }

    return answer.value;
}

async function find(selector) {
    return (await webdriver('POST', `/session/${session}/element`, { using: 'css selector', value: selector }))[ELEMENT];
}

async function waitForText(selector) {
    for (let attempt = 0; attempt < 50; attempt++) {
        const text = await webdriver('GET', `/session/${session}/element/${await find(selector)}/text`).catch(() => '');
        if (text.trim() !== '') {
            return text;
        }
        await new Promise((resolve) => setTimeout(resolve, 200));
    }

    return '';
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

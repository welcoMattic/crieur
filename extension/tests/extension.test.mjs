// npm test: the pure code of the extension, its manifests and its translations.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const shared = createRequire(import.meta.url)(join(root, 'src/lib/shared.js'));
const json = (path) => JSON.parse(readFileSync(join(root, path), 'utf8'));

test('the server address is normalized', () => {
    assert.equal(shared.normalizeServerUrl(' http://127.0.0.1:8000/ '), 'http://127.0.0.1:8000');
    assert.equal(shared.normalizeServerUrl('crieur.example.org'), 'http://crieur.example.org');
    assert.equal(shared.normalizeServerUrl('https://crieur.example.org/sub/'), 'https://crieur.example.org/sub');
    assert.equal(shared.normalizeServerUrl('ftp://example.org'), null);
    assert.equal(shared.normalizeServerUrl(''), null);
});

test('the host permission covers the server host only', () => {
    assert.equal(shared.originPattern('http://127.0.0.1:8000'), 'http://127.0.0.1/*');
    assert.equal(shared.originPattern('https://crieur.cleverapps.io'), 'https://crieur.cleverapps.io/*');
});

test('the payload keeps what the server expects and nothing else', () => {
    const payload = shared.buildPayload({
        url: 'https://example.org/a', title: '  A title ', content: '<p>Text</p>', siteName: 'Example', byline: '',
        lang: 'fr-FR', excerpt: 'Short.', image: 'data:image/png;base64,AAAA', publishedTime: '2026-11-18', cookies: 'never',
    }, { voice: 'male', language: 'xx' });

    assert.deepEqual(payload, {
        url: 'https://example.org/a', title: 'A title', content: '<p>Text</p>', language: 'auto', voice: 'male',
        siteName: 'Example', lang: 'fr-FR', excerpt: 'Short.', publishedTime: '2026-11-18',
    });
});

test('the name of the site leaves the title', () => {
    assert.equal(shared.cleanTitle('Crieur public \u2014 Wikip\u00e9dia', 'Wikipedia', 'https://fr.wikipedia.org/wiki/Crieur_public'), 'Crieur public');
    assert.equal(shared.cleanTitle('Town crier - Wikipedia', '', 'https://en.wikipedia.org/wiki/Town_crier'), 'Town crier');
    assert.equal(shared.cleanTitle('Why we read slower | The Field Notes', 'The Field Notes', 'https://example.org/essay'), 'Why we read slower');
    assert.equal(shared.cleanTitle('Symfony 8: what changes - a review', 'Symfony', 'https://symfony.com/blog'), 'Symfony 8: what changes - a review');
    assert.equal(shared.cleanTitle('Plain title', 'Example', 'https://example.org'), 'Plain title');
    assert.equal(shared.cleanTitle('A letter to me - me', '', 'https://medium.com/@someone/a-letter'), 'A letter to me - me');
    assert.equal(shared.cleanTitle('Notes on PHP - example.org', '', 'https://www.example.org/notes'), 'Notes on PHP');
});

test('answers of the server become messages', () => {
    assert.equal(shared.errorKey(401, 'unauthorized'), 'errorToken');
    assert.equal(shared.errorKey(422, 'extraction.no_text'), 'errorNoText');
    assert.equal(shared.errorKey(422, 'extraction.unsupported_language'), 'errorLanguage');
    assert.equal(shared.errorKey(500, undefined), 'errorServer');
});

test('every message is translated in English and French, within the store limits', () => {
    const en = json('src/_locales/en/messages.json');
    const fr = json('src/_locales/fr/messages.json');
    assert.deepEqual(Object.keys(fr).sort(), Object.keys(en).sort());
    for (const messages of [en, fr]) {
        assert.ok(messages.extName.message.length <= 75, 'name: 75 characters at most');
        assert.ok(messages.extDescription.message.length <= 132, 'description: 132 characters at most');
    }

    const used = new Set();
    const sources = ['src/popup/popup.html', 'src/popup/popup.js', 'src/options/options.html', 'src/options/options.js', 'src/background.js', 'src/lib/shared.js', 'src/manifest.json'];
    for (const file of sources) {
        const text = readFileSync(join(root, file), 'utf8');
        for (const match of text.matchAll(/data-i18n(?:-placeholder|-label)?="(\w+)"|\bt\('(\w+)'|getMessage\('(\w+)'|__MSG_(\w+)__|return '(error\w+)'|error: '(error\w+)'/g)) {
            used.add(match.slice(1).find(Boolean));
        }
    }
    for (const key of used) {
        assert.ok(key in en, `missing message: ${key}`);
    }
});

test('the build gives one manifest per browser', () => {
    execFileSync('node', ['build.mjs'], { cwd: root });
    const chrome = json('dist/chrome/manifest.json');
    const firefox = json('dist/firefox/manifest.json');

    assert.equal(chrome.manifest_version, 3);
    assert.deepEqual(chrome.background, { service_worker: 'background.js' });
    assert.equal(chrome.browser_specific_settings, undefined);
    assert.deepEqual(firefox.background, { scripts: ['lib/shared.js', 'background.js'] });
    assert.equal(firefox.browser_specific_settings.gecko.strict_min_version, '140.0');
    assert.deepEqual(firefox.browser_specific_settings.gecko.data_collection_permissions.required, ['websiteContent', 'browsingActivity']);
    // A Crieur on plain http elsewhere than this computer must stay reachable from Firefox.
    assert.doesNotMatch(firefox.content_security_policy.extension_pages, /upgrade-insecure-requests/);
    for (const manifest of [chrome, firefox]) {
        assert.equal(manifest.host_permissions, undefined, 'no host permission at install');
        assert.deepEqual(manifest.permissions, ['activeTab', 'scripting', 'storage', 'contextMenus']);
        for (const icon of Object.values(manifest.icons)) {
            assert.ok(readdirSync(join(root, 'src/icons')).includes(icon.replace('icons/', '')));
        }
    }
});

test('Defuddle is the unmodified release 0.19.6 from npm', () => {
    const digest = createHash('sha256').update(readFileSync(join(root, 'src/vendor/defuddle.js'))).digest('hex');
    assert.equal(digest, 'f40075dc7b7b0460c16f77b9486a0ee66907a19677d2984e198e94356116a1e8');
});

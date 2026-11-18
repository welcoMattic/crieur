// Builds the Crieur extension for each browser: dist/chrome and dist/firefox, and their zips.
// No dependency: it copies src/ and writes one manifest per browser.
//   node build.mjs          the two store packages
//   node build.mjs --e2e    also dist/chrome-e2e, allowed on 127.0.0.1 for the end-to-end test
import { cpSync, mkdirSync, readFileSync, writeFileSync, existsSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(fileURLToPath(import.meta.url));
const src = join(root, 'src');
const dist = join(root, 'dist');
const base = JSON.parse(readFileSync(join(src, 'manifest.json'), 'utf8'));

// The id of the add-on on addons.mozilla.org: it must never change once published.
const GECKO_ID = '{6f1c4d1e-3b7a-4a49-9c55-2f6e8f0b9d21}';

const targets = {
    chrome: {
        ...base,
        minimum_chrome_version: '120',
        background: { service_worker: 'background.js' },
    },
    firefox: {
        ...base,
        background: { scripts: ['lib/shared.js', 'background.js'] },
        browser_specific_settings: {
            gecko: {
                id: GECKO_ID,
                strict_min_version: '140.0',
                // The article and its address go to the reader's own Crieur server, on their request.
                data_collection_permissions: { required: ['websiteContent', 'browsingActivity'] },
            },
            // The built-in data consent of Firefox for Android starts with version 142.
            gecko_android: { strict_min_version: '142.0' },
        },
    },
};

if (process.argv.includes('--e2e')) {
    // Test only: the toolbar click that grants activeTab cannot be automated, so this variant may
    // read the test pages (127.0.0.1) and Wikipedia (the store screenshots) without it.
    targets['chrome-e2e'] = { ...targets.chrome, host_permissions: ['http://127.0.0.1/*', 'https://*.wikipedia.org/*'] };
}

for (const [name, manifest] of Object.entries(targets)) {
    const out = join(dist, name);
    mkdirSync(out, { recursive: true });
    cpSync(src, out, { recursive: true });
    writeFileSync(join(out, 'manifest.json'), JSON.stringify(manifest, null, 2) + '\n');
    if (!name.endsWith('-e2e')) {
        const zip = join(dist, `crieur-${name}-${manifest.version}.zip`);
        if (existsSync(zip)) {
            // zip updates an existing archive in place: start from a fresh name instead of deleting.
            execFileSync('zip', ['-q', '-r', '-X', '-FS', zip, '.'], { cwd: out });
        } else {
            execFileSync('zip', ['-q', '-r', '-X', zip, '.'], { cwd: out });
        }
        console.log(`${name}: ${out} and ${zip}`);
    } else {
        console.log(`${name}: ${out}`);
    }
}

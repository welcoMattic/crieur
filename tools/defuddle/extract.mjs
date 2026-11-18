// The article extractor of Crieur: Defuddle (by Steph Ango, the engine of Obsidian Web Clipper,
// also in the browser extension), on the page the server fetched.
//   defuddle <page url> < page.html > article.json
//   defuddle --version
// The answer is JSON on stdout; a page without article is {"error": "no_article"}, exit code 0.
import { Defuddle } from 'defuddle/node';
import packageInfo from 'defuddle/package.json' with { type: 'json' };

const { version } = packageInfo;

// Compiled with Bun, the process may exit before an asynchronous pipe write ends: a reader slower
// than the 64 KB pipe buffer would get a truncated answer. The write is awaited before the exit.
function write(stream, text) {
    return new Promise((resolve, reject) => stream.write(text, (error) => (error ? reject(error) : resolve())));
}

async function readStdin() {
    const chunks = [];
    for await (const chunk of process.stdin) {
        chunks.push(chunk);
    }

    return Buffer.concat(chunks).toString('utf8');
}

async function main() {
    if (process.argv[2] === '--version') {
        await write(process.stdout, version + '\n');
        return;
    }
    const url = process.argv[2] || 'about:blank';
    const html = await readStdin();
    const result = await Defuddle(html, url);
    if (!result || !result.content || !result.content.trim()) {
        await write(process.stdout, JSON.stringify({ error: 'no_article' }) + '\n');
        return;
    }
    await write(process.stdout, JSON.stringify({
        title: result.title || '',
        content: result.content,
        site: result.site || '',
        author: result.author || '',
        description: result.description || '',
        published: result.published || '',
        image: result.image || '',
        language: result.language || '',
        wordCount: result.wordCount || 0,
    }) + '\n');
}

main().catch(async (error) => {
    await write(process.stderr, `defuddle: ${error instanceof Error ? error.message : String(error)}\n`);
    process.exitCode = 1;
});

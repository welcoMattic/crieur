<?php

declare(strict_types=1);

/*
 * The tasks of Crieur, run with Castor (https://castor.jolicode.com): `castor list` shows them.
 * The app runs on this machine (PHP and the Symfony CLI), the voices on Ollama or Mistral AI.
 */

defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', true);

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;

use function Castor\context;
use function Castor\guard_min_version;
use function Castor\io;
use function Castor\run;

guard_min_version('1.8.0');

const EXTENSION_DIR = __DIR__.'/extension';
const DEFUDDLE_DIR = __DIR__.'/tools/defuddle';

#[AsTask(description: 'Installs everything: PHP dependencies, the article extractor, the database, the browser extension')]
function install(): void
{
    io()->title('Installing Crieur');

    io()->section('PHP dependencies');
    run(['composer', 'install', '--no-interaction']);

    io()->section('Article extractor (Defuddle, built with Bun)');
    defuddle_build();

    io()->section('Database');
    run(['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);

    io()->section('Browser extension');
    extension_build();

    io()->success('Crieur is installed. Check the voices with `castor doctor`, then start it with `castor start`.');
}

#[AsTask(description: 'Starts the web server and the three queue workers (Symfony CLI), in the background')]
function start(): void
{
    run(['symfony', 'server:start', '--daemon']);
    io()->success('Crieur runs on http://127.0.0.1:8000 (`castor logs` to follow it, `castor stop` to stop it).');
}

#[AsTask(description: 'Stops the web server and the queue workers')]
function stop(): void
{
    run(['symfony', 'server:stop']);
}

#[AsTask(description: 'Follows the logs of the web server and the queue workers')]
function logs(): void
{
    run(['symfony', 'server:log'], context: context()->withTimeout(null)->withAllowFailure());
}

#[AsTask(description: 'Checks the setup: database, extractor, voice engine, speech editor')]
function doctor(
    #[AsOption(description: 'Also synthesize one sentence and ask the speech editor one question')]
    bool $live = false,
): void {
    run(['php', 'bin/console', 'crieur:doctor', ...($live ? ['--live'] : [])], context: context()->withAllowFailure());
}

#[AsTask(description: 'Pulls the local models in Ollama: the Phonon voices, and the Ministral speech editor')]
function models(
    #[AsOption(description: 'Phonon voices only, without the speech editor (6 GB)')]
    bool $voicesOnly = false,
): void {
    run(['ollama', 'pull', 'phonon:40m-multilingual']);
    if (!$voicesOnly) {
        run(['ollama', 'pull', 'ministral-3:8b']);
    }
}

#[AsTask(description: 'Runs the tests: PHPUnit, PHPStan and the unit tests of the browser extension')]
function test(): void
{
    io()->section('PHPUnit');
    run(['php', 'bin/phpunit']);

    io()->section('PHPStan');
    run(['vendor/bin/phpstan', 'analyse', '--no-progress']);

    io()->section('Browser extension');
    run(['npm', 'test'], context: context()->withWorkingDirectory(EXTENSION_DIR));
}

#[AsTask(name: 'build', namespace: 'defuddle', description: 'Builds the article extractor (Defuddle) into tools/defuddle/build/defuddle')]
function defuddle_build(): void
{
    run([DEFUDDLE_DIR.'/build.sh']);
}

#[AsTask(name: 'build', namespace: 'extension', description: 'Builds the browser extension: extension/dist/{chrome,firefox} and their zips')]
function extension_build(): void
{
    $context = context()->withWorkingDirectory(EXTENSION_DIR);
    run(['npm', 'ci'], context: $context);
    run(['npm', 'run', 'build'], context: $context);
}

#[AsTask(name: 'lint', namespace: 'extension', description: 'Lints the Firefox package with web-ext')]
function extension_lint(): void
{
    run(['npm', 'run', 'lint'], context: context()->withWorkingDirectory(EXTENSION_DIR));
}

#[AsTask(name: 'e2e', namespace: 'extension', description: 'End-to-end tests of the extension in headless Chromium and Firefox (muted), on an isolated Crieur')]
function extension_e2e(
    #[AsOption(description: 'Chromium only')]
    bool $chromiumOnly = false,
): void {
    $context = context()->withWorkingDirectory(EXTENSION_DIR);
    run(['npm', 'run', 'e2e'], context: $context);
    if (!$chromiumOnly) {
        run(['npm', 'run', 'e2e:firefox'], context: $context);
    }
}

#[AsTask(name: 'up', namespace: 'docker', description: 'Builds and starts Crieur with Docker Compose (Phonon through the Ollama of the host)')]
function docker_up(): void
{
    run(['docker', 'compose', 'up', '--detach', '--build']);
    io()->success('Crieur runs on http://localhost:8000 (CRIEUR_PORT to change it).');
}

#[AsTask(name: 'down', namespace: 'docker', description: 'Stops the Docker Compose stack (the library stays in its volume)')]
function docker_down(): void
{
    run(['docker', 'compose', 'down']);
}

#[AsTask(name: 'build-server', namespace: 'docker', description: 'Builds the all-in-one server image (app, workers, Phonon servers): docker/clever/Dockerfile')]
function docker_build_server(): void
{
    run(['docker', 'build', '--platform', 'linux/amd64', '--file', 'docker/clever/Dockerfile', '--tag', 'crieur-server', '.']);
}

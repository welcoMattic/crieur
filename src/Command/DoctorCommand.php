<?php

declare(strict_types=1);

namespace App\Command;

use App\Editor\EditorUnavailable;
use App\Editor\SpeechEditor;
use App\Enum\VoiceGender;
use App\Speech\SpeechSynthesizer;
use App\Speech\VoiceCatalog;
use App\Text\Language;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'crieur:doctor', description: 'Checks that the voices, the speech editor and the database are ready, and says how to fix what is not')]
final readonly class DoctorCommand
{
    public function __construct(
        private VoiceCatalog $voices,
        private SpeechSynthesizer $synthesizer,
        private SpeechEditor $editor,
        private Connection $connection,
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'OLLAMA_HOST_URL')]
        private string $ollamaUrl,
        #[Autowire(env: 'MISTRAL_API_KEY')]
        private string $mistralApiKey,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Also synthesize one sentence and ask the speech editor one question (a few seconds)')]
        bool $live = false,
    ): int {
        $rows = [];
        $problems = 0;
        $check = static function (string $what, bool $ok, string $detail, string $fix = '') use (&$rows, &$problems): void {
            $rows[] = [$ok ? '<info>ok</info>' : '<error>no</error>', $what, $ok ? $detail : trim($detail.' '.$fix)];
            $problems += $ok ? 0 : 1;
        };

        try {
            $this->connection->fetchOne('SELECT COUNT(*) FROM article');
            $check('Database', true, 'SQLite schema is up to date');
        } catch (\Throwable) {
            $check('Database', false, 'The schema is missing.', 'Run: php bin/console doctrine:migrations:migrate');
        }

        $engine = $this->voices->defaultEngine();
        $check('Voice engine', true, \sprintf('%s (%s)', $engine->name, $engine->label));

        $ollamaModels = $this->ollamaModels();
        if ($engine->local || 'ollama' === $this->editor->backend()) {
            $check('Ollama', null !== $ollamaModels, $this->ollamaUrl, 'is not reachable. Install Ollama 0.40.11 or later and start it.');
        }

        if ('phonon' === $engine->name) {
            $installed = null !== $ollamaModels && \in_array($engine->model, $ollamaModels, true);
            $check('Phonon voices', $installed, $engine->model, \sprintf('is not pulled. Run: ollama pull %s', $engine->model));
        }

        if ('voxtral' === $engine->name || 'mistral' === $this->editor->backend()) {
            $check('Mistral API key', $this->mistralKeyWorks(), '' === trim($this->mistralApiKey) ? 'MISTRAL_API_KEY is empty.' : 'MISTRAL_API_KEY', 'Set a valid key in .env.local (MISTRAL_API_KEY=...).');
        }

        $backend = $this->editor->backend();
        if (null === $backend) {
            $check('Speech editor', true, 'off: deterministic rules only (set CRIEUR_EDITOR=mistral or ollama for the LLM editor)');
        } elseif ('ollama' === $backend) {
            $model = SpeechEditor::MODELS['ollama'];
            $check('Speech editor', null !== $ollamaModels && \in_array($model, $ollamaModels, true), \sprintf('%s through Ollama', $model), \sprintf('is not pulled. Run: ollama pull %s', $model));
        } else {
            $check('Speech editor', true, \sprintf('%s through Mistral AI', SpeechEditor::MODELS['mistral']));
        }

        $ffmpeg = (new ExecutableFinder())->find('ffmpeg');
        $check('ffmpeg (optional)', null !== $ffmpeg, null !== $ffmpeg ? 'podcast episodes in AAC (M4A)' : 'not found: podcast episodes stay in WAV');

        if ($live) {
            try {
                $voice = $this->voices->voice($engine->name, Language::English, VoiceGender::Female);
                $audio = $this->synthesizer->synthesize('Crieur is ready to read.', $voice);
                $check('Live synthesis', true, \sprintf('%s answered %.1f s of audio', $voice->name, $audio['durationMs'] / 1000));
            } catch (\Throwable $e) {
                $check('Live synthesis', false, $e->getMessage());
            }

            if (null !== $backend) {
                try {
                    $started = microtime(true);
                    $edited = $this->editor->edit(Language::English, 'Doctor', [1 => 'On 11/17/2026, prices rose by 3.5%.'], false);
                    $check('Live speech editor', [] !== $edited->spoken, \sprintf('"%s" in %.1f s', $edited->spoken[1] ?? '', microtime(true) - $started));
                } catch (EditorUnavailable $e) {
                    $check('Live speech editor', false, $e->getMessage());
                }
            }
        }

        $io->table(['', 'Check', 'Detail'], $rows);
        if (0 === $problems) {
            $io->success('Crieur is ready. Start it with: symfony serve');

            return Command::SUCCESS;
        }

        $io->warning(\sprintf('%d check(s) failed: see the detail column.', $problems));

        return Command::FAILURE;
    }

    /**
     * @return list<string>|null the models Ollama has pulled, or null when it does not answer
     */
    private function ollamaModels(): ?array
    {
        try {
            $data = $this->httpClient->request('GET', rtrim($this->ollamaUrl, '/').'/api/tags', ['timeout' => 3])->toArray();
        } catch (\Throwable) {
            return null;
        }

        $models = [];
        foreach ($data['models'] ?? [] as $model) {
            if (\is_array($model) && \is_string($model['name'] ?? null)) {
                $models[] = $model['name'];
            }
        }

        return $models;
    }

    private function mistralKeyWorks(): bool
    {
        if ('' === trim($this->mistralApiKey)) {
            return false;
        }

        try {
            return 200 === $this->httpClient->request('GET', 'https://api.mistral.ai/v1/models', ['auth_bearer' => $this->mistralApiKey, 'timeout' => 5])->getStatusCode();
        } catch (\Throwable) {
            return false;
        }
    }
}

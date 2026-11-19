<?php

declare(strict_types=1);

namespace App\Setup;

use App\Settings\EngineSettings;
use App\Speech\PhononServers;
use App\Speech\VoiceCatalog;
use App\Speech\VoiceEngine;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tells which voice engine and which speech editor are active, and whether they can work now:
 * Ollama reachable with the Phonon and Ministral models pulled (or the Phonon servers answering,
 * when CRIEUR_PHONON_SERVERS lists them), the Mistral API key set.
 */
final readonly class SetupInspector
{
    /**
     * The models of the speech editor, as config/packages/ai.yaml names them.
     */
    public const string EDITOR_MODEL_DMR = 'ai/ministral3:8B-Q4_K_M';
    public const string EDITOR_MODEL_MISTRAL = 'mistral-small-latest';

    public function __construct(
        private HttpClientInterface $httpClient,
        private VoiceCatalog $voices,
        #[Autowire(env: 'OLLAMA_HOST_URL')]
        private string $ollamaUrl,
        #[Autowire(env: 'MISTRAL_API_KEY')]
        private string $mistralApiKey,
        #[Autowire(param: 'crieur.editor')]
        private string $editorSetting,
        private float $timeout = 1.5,
        private ?EngineSettings $settings = null,
        #[Autowire(env: 'CRIEUR_PHONON_SERVERS')]
        private string $phononServers = '',
        #[Autowire(env: 'DOCKER_MODEL_RUNNER_URL')]
        private string $dmrUrl = 'http://127.0.0.1:12434',
    ) {
    }

    public function editorSetting(): string
    {
        $setting = $this->settings?->editor() ?? $this->editorSetting;

        return 'ollama' === $setting ? 'dmr' : $setting;
    }

    public function inspect(): SetupReport
    {
        $models = $this->ollamaModels();
        $dmrModels = $this->dmrModels();
        $keySet = '' !== trim($this->mistralApiKey);
        $active = $this->voices->defaultEngine()->name;
        $servers = PhononServers::fromString($this->phononServers);
        $serversDown = $servers->isEmpty() ? null : $this->serversDown($servers);

        $engines = array_map(
            fn (VoiceEngine $engine): Check => $this->engine($engine, $engine->name === $active, $models, $keySet, $serversDown),
            $this->voices->engines(),
        );

        return new SetupReport(
            engines: $engines,
            editor: $this->editor($dmrModels, $keySet),
            editorSetting: $this->editorSetting(),
            ollamaUrl: $this->displayedOllamaUrl(),
            ollamaReachable: null !== $models,
            ollamaModels: $models ?? [],
            mistralKeySet: $keySet,
            phononServers: $servers->displayed(),
            dmrUrl: self::displayed($this->dmrUrl),
            dmrModels: $dmrModels,
        );
    }

    /**
     * @param ?list<string> $models      null when Ollama cannot be reached
     * @param ?list<string> $serversDown the Phonon servers that do not answer, null without Phonon servers
     */
    private function engine(VoiceEngine $engine, bool $active, ?array $models, bool $keySet, ?array $serversDown): Check
    {
        $issue = match (true) {
            !$engine->local => $keySet ? null : 'setup.issue.key_missing',
            null !== $serversDown => [] === $serversDown ? null : 'setup.issue.phonon_unreachable',
            null === $models => 'setup.issue.ollama_unreachable',
            default => self::hasModel($models, $engine->model) ? null : 'setup.issue.model_missing',
        };

        return new Check($engine->name, $engine->label, $engine->model, $active, $engine->local, null === $issue, $issue);
    }

    /**
     * Mirrors CRIEUR_EDITOR: "auto" uses Mistral when the key is set, else the rules only.
     *
     * @param ?list<string> $dmrModels the models of Docker Model Runner, null when it does not answer
     */
    private function editor(?array $dmrModels, bool $keySet): Check
    {
        return match ($this->editorSetting()) {
            'mistral' => new Check('mistral', 'Mistral AI', self::EDITOR_MODEL_MISTRAL, true, false, $keySet, $keySet ? null : 'setup.issue.key_missing'),
            'dmr' => new Check(
                'dmr',
                'Docker Model Runner',
                self::EDITOR_MODEL_DMR,
                true,
                true,
                null !== $dmrModels && self::hasDmrModel($dmrModels),
                null === $dmrModels ? 'setup.issue.dmr_unreachable' : (self::hasDmrModel($dmrModels) ? null : 'setup.issue.dmr_model_missing'),
            ),
            'auto' => $keySet
                ? new Check('mistral', 'Mistral AI', self::EDITOR_MODEL_MISTRAL, true, false, true)
                : new Check('rules', 'Rules', null, true, true, true),
            default => new Check('rules', 'Rules', null, true, true, true),
        };
    }

    /**
     * @return ?list<string> the models pulled in Ollama, null when it does not answer
     */
    private function ollamaModels(): ?array
    {
        try {
            $data = $this->httpClient->request('GET', rtrim($this->ollamaUrl, '/').'/api/tags', [
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout * 2,
            ])->toArray();
        } catch (ExceptionInterface) {
            return null;
        }

        $models = [];
        foreach (\is_array($data['models'] ?? null) ? $data['models'] : [] as $model) {
            if (\is_array($model) && \is_string($model['name'] ?? null)) {
                $models[] = $model['name'];
            }
        }

        return $models;
    }

    /**
     * @return ?list<string> the models of Docker Model Runner, null when it does not answer
     */
    private function dmrModels(): ?array
    {
        try {
            $data = $this->httpClient->request('GET', rtrim($this->dmrUrl, '/').'/engines/v1/models', [
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout * 2,
            ])->toArray();
        } catch (ExceptionInterface) {
            return null;
        }

        $models = [];
        foreach (\is_array($data['data'] ?? null) ? $data['data'] : [] as $model) {
            if (\is_array($model) && \is_string($model['id'] ?? null)) {
                $models[] = $model['id'];
            }
        }

        return $models;
    }

    /**
     * Docker Model Runner names its models with their registry: "docker.io/ai/ministral3:8B-Q4_K_M".
     *
     * @param list<string> $models
     */
    public static function hasDmrModel(array $models): bool
    {
        foreach ($models as $model) {
            if (0 === strcasecmp(preg_replace('~^docker\.io/~', '', $model) ?? $model, self::EDITOR_MODEL_DMR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string> the languages whose Phonon server does not answer GET /v1/models
     */
    private function serversDown(PhononServers $servers): array
    {
        $down = [];
        foreach ($servers->servers as $language => $url) {
            try {
                $this->httpClient->request('GET', $url.'/v1/models', [
                    'timeout' => $this->timeout,
                    'max_duration' => $this->timeout * 2,
                ])->toArray();
            } catch (ExceptionInterface) {
                $down[] = $language;
            }
        }

        return $down;
    }

    /**
     * @param list<string> $models
     */
    private static function hasModel(array $models, string $expected): bool
    {
        foreach ($models as $model) {
            if ($model === $expected || $model === $expected.':latest' || (!str_contains($expected, ':') && str_starts_with($model, $expected.':'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The address of Ollama without credentials, should it hold any.
     */
    private function displayedOllamaUrl(): string
    {
        return self::displayed($this->ollamaUrl);
    }

    private static function displayed(string $url): string
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host'])) {
            return $url;
        }

        return \sprintf('%s://%s%s%s', $parts['scheme'] ?? 'http', $parts['host'], isset($parts['port']) ? ':'.$parts['port'] : '', $parts['path'] ?? '');
    }
}

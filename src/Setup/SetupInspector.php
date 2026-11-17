<?php

declare(strict_types=1);

namespace App\Setup;

use App\Speech\VoiceCatalog;
use App\Speech\VoiceEngine;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tells which voice engine and which speech editor are active, and whether they can work now:
 * Ollama reachable with the Phonon and Ministral models pulled, the Mistral API key set.
 */
final readonly class SetupInspector
{
    /**
     * The models of the speech editor, as config/packages/ai.yaml names them.
     */
    public const string EDITOR_MODEL_OLLAMA = 'ministral-3:8b';
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
    ) {
    }

    public function inspect(): SetupReport
    {
        $models = $this->ollamaModels();
        $keySet = '' !== trim($this->mistralApiKey);
        $active = $this->voices->defaultEngine()->name;

        $engines = array_map(
            fn (VoiceEngine $engine): Check => $this->engine($engine, $engine->name === $active, $models, $keySet),
            $this->voices->engines(),
        );

        return new SetupReport(
            engines: $engines,
            editor: $this->editor($models, $keySet),
            editorSetting: $this->editorSetting,
            ollamaUrl: $this->displayedOllamaUrl(),
            ollamaReachable: null !== $models,
            ollamaModels: $models ?? [],
            mistralKeySet: $keySet,
        );
    }

    /**
     * @param ?list<string> $models null when Ollama cannot be reached
     */
    private function engine(VoiceEngine $engine, bool $active, ?array $models, bool $keySet): Check
    {
        $issue = $engine->local
            ? (null === $models ? 'setup.issue.ollama_unreachable' : (self::hasModel($models, $engine->model) ? null : 'setup.issue.model_missing'))
            : ($keySet ? null : 'setup.issue.key_missing');

        return new Check($engine->name, $engine->label, $engine->model, $active, $engine->local, null === $issue, $issue);
    }

    /**
     * Mirrors CRIEUR_EDITOR: "auto" uses Mistral when the key is set, else the rules only.
     *
     * @param ?list<string> $models
     */
    private function editor(?array $models, bool $keySet): Check
    {
        return match ($this->editorSetting) {
            'mistral' => new Check('mistral', 'Mistral AI', self::EDITOR_MODEL_MISTRAL, true, false, $keySet, $keySet ? null : 'setup.issue.key_missing'),
            'ollama' => new Check(
                'ollama',
                'Ollama',
                self::EDITOR_MODEL_OLLAMA,
                true,
                true,
                null !== $models && self::hasModel($models, self::EDITOR_MODEL_OLLAMA),
                null === $models ? 'setup.issue.ollama_unreachable' : (self::hasModel($models, self::EDITOR_MODEL_OLLAMA) ? null : 'setup.issue.model_missing'),
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
        $parts = parse_url($this->ollamaUrl);
        if (false === $parts || !isset($parts['host'])) {
            return $this->ollamaUrl;
        }

        return \sprintf('%s://%s%s%s', $parts['scheme'] ?? 'http', $parts['host'], isset($parts['port']) ? ':'.$parts['port'] : '', $parts['path'] ?? '');
    }
}

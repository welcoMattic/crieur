<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * What the settings page shows: the voice engines, the speech editor and the services behind them.
 * It never holds a secret: only whether the Mistral API key is set.
 */
final readonly class SetupReport
{
    /**
     * @param list<Check>           $engines
     * @param list<string>          $ollamaModels
     * @param array<string, string> $phononServers the Phonon servers by language, when Phonon does not go through Ollama
     */
    public function __construct(
        public array $engines,
        public Check $editor,
        public string $editorSetting,
        public string $ollamaUrl,
        public bool $ollamaReachable,
        public array $ollamaModels,
        public bool $mistralKeySet,
        public array $phononServers = [],
    ) {
    }

    public function activeEngine(): ?Check
    {
        foreach ($this->engines as $engine) {
            if ($engine->active) {
                return $engine;
            }
        }

        return null;
    }

    public function isReady(): bool
    {
        return true === $this->activeEngine()?->ready && $this->editor->ready;
    }

    /**
     * Whether this voice engine can read now: the settings page offers only those.
     */
    public function canUseEngine(string $name): bool
    {
        foreach ($this->engines as $engine) {
            if ($engine->name === $name) {
                return $engine->active || $engine->ready;
            }
        }

        return false;
    }

    /**
     * Whether this choice of speech editor can work now ("auto" and "off" always can).
     */
    public function canUseEditor(string $choice): bool
    {
        return match ($choice) {
            'auto', 'off' => true,
            'mistral' => $this->mistralKeySet,
            'ollama' => $this->ollamaReachable && \in_array(SetupInspector::EDITOR_MODEL_OLLAMA, $this->ollamaModels, true),
            default => false,
        };
    }
}

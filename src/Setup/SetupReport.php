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
     * @param list<Check>  $engines
     * @param list<string> $ollamaModels
     */
    public function __construct(
        public array $engines,
        public Check $editor,
        public string $editorSetting,
        public string $ollamaUrl,
        public bool $ollamaReachable,
        public array $ollamaModels,
        public bool $mistralKeySet,
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
}

<?php

declare(strict_types=1);

namespace App\Twig;

use App\Editor\SpeechEditor;
use App\Speech\VoiceCatalog;
use App\Speech\VoiceEngine;
use Twig\Attribute\AsTwigFunction;

/**
 * The voice engines and the speech editor, for the footer, the home page, the article pages and
 * the settings page.
 */
final readonly class Voices
{
    public function __construct(
        private VoiceCatalog $catalog,
        private SpeechEditor $editor,
    ) {
    }

    /**
     * The engine of new articles.
     */
    #[AsTwigFunction('voice_engine')]
    public function engine(): VoiceEngine
    {
        return $this->catalog->defaultEngine();
    }

    /**
     * The engine of an article, by its name; null for an engine no longer configured.
     */
    #[AsTwigFunction('voice_engine_named')]
    public function engineNamed(string $name): ?VoiceEngine
    {
        try {
            return $this->catalog->engine($name);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The model of the speech editor in use, null when only the rules prepare the text.
     */
    #[AsTwigFunction('editor_model')]
    public function editorModel(): ?string
    {
        return $this->editor->model();
    }
}

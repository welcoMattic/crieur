<?php

declare(strict_types=1);

namespace App\Twig;

use App\Speech\VoiceCatalog;
use App\Speech\VoiceEngine;
use Twig\Attribute\AsTwigFunction;

/**
 * The voice engine in use, for the footer and the settings page.
 */
final readonly class Voices
{
    public function __construct(
        private VoiceCatalog $catalog,
    ) {
    }

    #[AsTwigFunction('voice_engine')]
    public function engine(): VoiceEngine
    {
        return $this->catalog->defaultEngine();
    }
}

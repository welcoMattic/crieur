<?php

declare(strict_types=1);

namespace App\AI\Platform\Mistral\TextToSpeech;

use App\AI\Platform\Mistral\TextToSpeech;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

final class ModelCatalog extends AbstractModelCatalog
{
    private const array CAPABILITIES = [Capability::INPUT_TEXT, Capability::OUTPUT_AUDIO, Capability::TEXT_TO_SPEECH];

    public function __construct()
    {
        $this->models = [
            'voxtral-mini-tts-latest' => ['class' => TextToSpeech::class, 'capabilities' => self::CAPABILITIES],
            'voxtral-mini-tts-3-0' => ['class' => TextToSpeech::class, 'capabilities' => self::CAPABILITIES],
        ];
    }
}

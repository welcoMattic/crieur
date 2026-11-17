<?php

declare(strict_types=1);

namespace App\AI\Platform\Ollama\TextToSpeech;

use App\AI\Platform\Ollama\TextToSpeech;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

/**
 * The speech models of Ollama. A tag such as "phonon:40m-multilingual" resolves to its base model.
 */
final class ModelCatalog extends AbstractModelCatalog
{
    public function __construct()
    {
        $this->models = [
            'phonon' => [
                'class' => TextToSpeech::class,
                'capabilities' => [Capability::INPUT_TEXT, Capability::OUTPUT_AUDIO, Capability::TEXT_TO_SPEECH],
            ],
        ];
    }
}

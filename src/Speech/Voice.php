<?php

declare(strict_types=1);

namespace App\Speech;

use App\Enum\VoiceGender;
use App\Text\Language;

/**
 * A voice of an engine: the model to call, the voice id it expects, and the name shown to the listener.
 */
final readonly class Voice
{
    /**
     * @param non-empty-string $model
     */
    public function __construct(
        public string $engine,
        public string $model,
        public string $id,
        public string $name,
        public Language $language,
        public VoiceGender $gender,
    ) {
    }
}

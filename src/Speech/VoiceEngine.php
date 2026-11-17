<?php

declare(strict_types=1);

namespace App\Speech;

/**
 * A voice engine as configured in config/packages/crieur.yaml.
 */
final readonly class VoiceEngine
{
    /**
     * @param non-empty-string $model
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $model,
        public string $license,
        public bool $local,
    ) {
    }

    /**
     * Phonon reads digits badly at the end of a sentence: integers are written in words first.
     */
    public function spellsIntegers(): bool
    {
        return str_starts_with($this->model, 'phonon');
    }
}

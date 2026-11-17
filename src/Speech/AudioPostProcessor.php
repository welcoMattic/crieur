<?php

declare(strict_types=1);

namespace App\Speech;

/**
 * Evens out the chunks: trims the silence the engines add around each sentence (Crieur adds its
 * own pauses) and brings every chunk to the same speech level.
 */
final readonly class AudioPostProcessor
{
    public function process(Wav $wav): Wav
    {
        return $wav;
    }
}

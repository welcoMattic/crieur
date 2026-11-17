<?php

declare(strict_types=1);

namespace App\Text;

/**
 * Estimates the listening time of a text from its characters and the speed (R-PB-9).
 */
final readonly class ReadingTime
{
    /**
     * Characters per second at speed 1.0. The Swift ProgressEstimator uses one rate for both
     * languages, so French and English share it until measures say otherwise.
     */
    public const float CHARACTERS_PER_SECOND = 15.0;

    public static function charactersPerSecond(Language $language): float
    {
        return match ($language) {
            Language::French, Language::English => self::CHARACTERS_PER_SECOND,
        };
    }

    /**
     * @param float $speed the playback rate (1.0 is the normal speed)
     */
    public static function estimateSeconds(string $spokenText, Language $language, float $speed = 1.0): int
    {
        if ($speed <= 0.0) {
            throw new \InvalidArgumentException(\sprintf('The speed must be positive, %s given.', $speed));
        }

        return (int) round(mb_strlen($spokenText) / self::charactersPerSecond($language) / $speed);
    }
}

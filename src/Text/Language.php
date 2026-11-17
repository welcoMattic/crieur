<?php

declare(strict_types=1);

namespace App\Text;

enum Language: string
{
    case French = 'fr';
    case English = 'en';

    /**
     * The ICU locale used for sentence breaking and number spelling.
     */
    public function locale(): string
    {
        return match ($this) {
            self::French => 'fr_FR',
            self::English => 'en_US',
        };
    }

    /**
     * The longest chunk sent to the voice engine, in characters (French words are longer).
     */
    public function chunkLimit(): int
    {
        return match ($this) {
            self::French => 300,
            self::English => 400,
        };
    }
}

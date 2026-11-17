<?php

declare(strict_types=1);

namespace App\Enum;

use App\Text\Language;

/**
 * The language asked for an article: detected from the text, or forced by the listener.
 */
enum LanguageSetting: string
{
    case Auto = 'auto';
    case French = 'fr';
    case English = 'en';

    public function forced(): ?Language
    {
        return match ($this) {
            self::Auto => null,
            self::French => Language::French,
            self::English => Language::English,
        };
    }
}

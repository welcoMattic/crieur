<?php

declare(strict_types=1);

namespace App\Text;

/**
 * Spells numbers, amounts and French ordinals with the ICU spell-out rules (port of NumberSpeller
 * in TextNormalizer.swift and of the integer rules of SpeechText.swift).
 */
final readonly class NumberSpeller
{
    private \NumberFormatter $formatter;

    public function __construct(
        public Language $language,
    ) {
        $this->formatter = new \NumberFormatter($language->locale(), \NumberFormatter::SPELLOUT);
    }

    public function percentWord(): string
    {
        return Language::French === $this->language ? 'pour cent' : 'percent';
    }

    /**
     * "21" gives "vingt-et-un" (the ICU form, kept for the ordinals) or "twenty-one".
     */
    public function spell(int $value): string
    {
        $words = $this->formatter->format($value);

        return false === $words ? (string) $value : $words;
    }

    /**
     * The value of an integer part with its thousands separators; null when it is too large.
     */
    public static function integer(string $part): ?int
    {
        $digits = (string) preg_replace('/\D/u', '', $part);
        if ('' === $digits || !ctype_digit($digits) || \strlen($digits) > 18) {
            return null;
        }

        return (int) $digits;
    }

    /**
     * "3,5" gives "trois virgule cinq"; "3.25" gives "three point two five".
     */
    public function number(string $integerPart, ?string $decimalPart): string
    {
        $value = self::integer($integerPart);
        if (null === $value) {
            return $integerPart.(null === $decimalPart ? '' : (Language::French === $this->language ? ',' : '.').$decimalPart);
        }

        $words = $this->spell($value);
        if (null !== $decimalPart && '' !== $decimalPart) {
            $words .= ' '.(Language::French === $this->language ? 'virgule' : 'point').' '.$this->spellDecimals($decimalPart);
        }

        return $words;
    }

    /**
     * "3,50 €" gives "trois euros cinquante", "2 M€" gives "deux millions d’euros".
     */
    public function money(string $integerPart, ?string $decimalPart, ?string $scale): string
    {
        $value = self::integer($integerPart);
        if (null === $value) {
            return $this->number($integerPart, $decimalPart).' euros';
        }

        $euro = $value >= 2 ? 'euros' : 'euro';
        if (null !== $scale) {
            $amount = $this->number($integerPart, $decimalPart);
            $plural = $value >= 2;

            $factor = self::scaleFactor($scale);
            if (Language::French === $this->language) {
                return match ($factor) {
                    1_000 => $amount.' mille euros',
                    1_000_000 => $amount.' '.($plural ? 'millions' : 'million').' d’euros',
                    default => $amount.' '.($plural ? 'milliards' : 'milliard').' d’euros',
                };
            }

            return match ($factor) {
                1_000 => $amount.' thousand euros',
                1_000_000 => $amount.' million euros',
                default => $amount.' billion euros',
            };
        }

        if (null === $decimalPart) {
            return $this->spell($value).' '.$euro;
        }
        if (\strlen($decimalPart) > 2 || !ctype_digit($decimalPart)) {
            return $this->number($integerPart, $decimalPart).' euros';
        }

        $cents = 1 === \strlen($decimalPart) ? (int) $decimalPart * 10 : (int) $decimalPart;

        return 0 === $cents ? $this->spell($value).' '.$euro : $this->spell($value).' '.$euro.' '.$this->spell($cents);
    }

    /**
     * "1er" gives "premier", "2e" gives "deuxième", "XIXe" gives "dix-neuvième". Null keeps the text.
     */
    public function frenchOrdinal(?int $value, string $suffix): ?string
    {
        if (null === $value || $value <= 0) {
            return null;
        }

        $feminine = \in_array($suffix, ['re', 'res', 'ère', 'ères', 'ʳᵉ', 'nde', 'ndes'], true);
        if (str_starts_with($suffix, 'nd') && 2 === $value) {
            $word = $feminine ? 'seconde' : 'second';
        } elseif (1 === $value) {
            $word = $feminine ? 'première' : 'premier';
        } else {
            $word = self::ordinalFromCardinal($this->spell($value));
        }

        return str_ends_with($suffix, 's') ? $word.'s' : $word;
    }

    /**
     * Words of a plain integer (R-TS-16). French writes "vingt et un" (no hyphens around "et").
     * English: no "and", years from 1100 to 2099 as years. A code ("007") or an identifier (more
     * than 15 digits) stays as digits.
     */
    public function spellInteger(string $digits): string
    {
        if ((\strlen($digits) > 1 && str_starts_with($digits, '0')) || \strlen($digits) > 15 || !ctype_digit($digits)) {
            return $digits;
        }

        $value = (int) $digits;
        if (Language::French === $this->language) {
            return str_replace('-et-', ' et ', $this->spell($value));
        }

        return 4 === \strlen($digits) && $value >= 1100 && $value <= 2099 ? $this->englishYear($value) : $this->spell($value);
    }

    /**
     * "nineteen eighty-four", "nineteen hundred", "nineteen oh-five", "twenty ten"; "two thousand"
     * and "two thousand five" as cardinals (the rules of num2words).
     */
    public function englishYear(int $value): string
    {
        $high = intdiv($value, 100);
        $low = $value % 100;
        if (0 === $high % 10 && $low < 10) {
            return $this->spell($value);
        }

        $lowText = match (true) {
            0 === $low => 'hundred',
            $low < 10 => 'oh-'.$this->spell($low),
            default => $this->spell($low),
        };

        return $this->spell($high).' '.$lowText;
    }

    public static function ordinalFromCardinal(string $cardinal): string
    {
        $space = strrpos($cardinal, ' ');
        $hyphen = strrpos($cardinal, '-');
        $position = max(false === $space ? -1 : $space, false === $hyphen ? -1 : $hyphen);
        $head = $position >= 0 ? substr($cardinal, 0, $position + 1) : '';
        $last = $position >= 0 ? substr($cardinal, $position + 1) : $cardinal;

        if ('vingts' === $last || 'cents' === $last) {
            $last = substr($last, 0, -1);
        }
        if (str_ends_with($last, 'q')) {
            $last .= 'u';
        } elseif (str_ends_with($last, 'f')) {
            $last = substr($last, 0, -1).'v';
        } elseif (str_ends_with($last, 'e')) {
            $last = substr($last, 0, -1);
        }

        return $head.$last.'ième';
    }

    public static function romanValue(string $roman): ?int
    {
        $values = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100];
        $total = 0;
        $previous = 0;
        foreach (array_reverse(str_split($roman)) as $char) {
            $value = $values[$char] ?? null;
            if (null === $value) {
                return null;
            }
            $total += $value < $previous ? -$value : $value;
            $previous = max($previous, $value);
        }

        return $total > 0 ? $total : null;
    }

    private static function scaleFactor(string $scale): int
    {
        return match (strtolower($scale)) {
            'k', 'mille', 'thousand' => 1_000,
            'm', 'mn', 'million', 'millions' => 1_000_000,
            default => 1_000_000_000,
        };
    }

    private function spellDecimals(string $digits): string
    {
        $values = array_map('intval', array_values(array_filter(str_split($digits), ctype_digit(...))));
        if (Language::English === $this->language || \strlen($digits) > 3) {
            return implode(' ', array_map($this->spell(...), $values));
        }

        $rest = ltrim($digits, '0');
        $parts = array_fill(0, \strlen($digits) - \strlen($rest), $this->spell(0));
        if ('' !== $rest && ctype_digit($rest)) {
            $parts[] = $this->spell((int) $rest);
        }

        return implode(' ', $parts);
    }
}

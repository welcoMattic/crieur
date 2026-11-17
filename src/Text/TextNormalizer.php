<?php

declare(strict_types=1);

namespace App\Text;

/**
 * Writes a text for the ear: the text the voice engine receives.
 *
 * Two steps, as in the Swift app: `writeOutSymbols()` (TextNormalizer.swift, R-TX-5) writes in
 * words decimal numbers, thousands, `%`, `€`, French ordinals and abbreviations; then
 * `prepareForSpeech()` (SpeechText.swift, R-TS-16) collapses the whitespace, writes "M." before a
 * lower-case word as "Monsieur" and, for engines that misread final digits, plain integers in words.
 */
final readonly class TextNormalizer
{
    private const string GAP = '[ \x{A0}\x{2009}\x{202F}]?';
    private const string SCALE = '(k|mn|m|Mds|Md|M|bn|B)';
    private const string WORD_SCALE = '[ \x{A0}](thousand|million|billion|mille|millions?|milliards?)\b';

    /**
     * Group 1 is the integer part with its thousands separators, group 2 the decimal part.
     */
    private const string FRENCH_NUMBER = '(?<![\p{L}\d.,])(\d{1,3}(?:[ \x{A0}\x{2009}\x{202F}]\d{3})+|\d{1,3}(?:\.\d{3}){2,}|\d+)(?:,(\d+))?(?!\d|[.,]\d)';
    private const string ENGLISH_NUMBER = '(?<![\p{L}\d.,])(\d{1,3}(?:,\d{3})+|\d{1,3}(?:[\x{A0}\x{2009}\x{202F}]\d{3})+|\d+)(?:\.(\d+))?(?!\d|[.,]\d)';

    private const string ORDINAL_SUFFIX = '(ers|er|res|re|ères|ère|èmes|ème|es|e|è|ndes|nde|nds|nd|ᵉʳ|ʳᵉ|ᵉ)';
    private const string ARABIC_ORDINAL = '~(?<![\p{L}\d.,])(\d{1,6})'.self::ORDINAL_SUFFIX.'(?![\p{L}\d])~u';
    private const string ROMAN_ORDINAL = '~(?<![\p{L}\d])((?=[IVXLC])C{0,3}(?:XC|XL|L?X{0,3})(?:IX|IV|V?I{0,3}))'.self::ORDINAL_SUFFIX.'(?![\p{L}\d])~u';

    private const string NAME = '(?=[ \x{A0}\x{2009}\x{202F}]+\p{Lu})';
    private const string SENTENCE_END = '(?=\s*$|\s+[\p{Lu}«"“])';

    private const array FRENCH_ABBREVIATIONS = [
        '~\bav\. ?J\.-C\.'.self::SENTENCE_END.'~u' => 'avant Jésus-Christ.',
        '~\bav\. ?J\.-C\.~u' => 'avant Jésus-Christ',
        '~\bapr\. ?J\.-C\.'.self::SENTENCE_END.'~u' => 'après Jésus-Christ.',
        '~\bapr\. ?J\.-C\.~u' => 'après Jésus-Christ',
        '~\bJ\.-C\.'.self::SENTENCE_END.'~u' => 'Jésus-Christ.',
        '~\bJ\.-C\.~u' => 'Jésus-Christ',
        '~\betc\.(?:\.\.)?'.self::SENTENCE_END.'~u' => 'et cetera.',
        '~\betc\.(?:\.\.)?~u' => 'et cetera',
        '~\bc\.-à-d\.~u' => 'c’est-à-dire',
        '~\bMM\.'.self::NAME.'~u' => 'Messieurs',
        '~\bM\.'.self::NAME.'~u' => 'Monsieur',
        '~\bMmes\b\.?~u' => 'Mesdames',
        '~\bMme\b\.?~u' => 'Madame',
        '~\bMlles\b\.?~u' => 'Mesdemoiselles',
        '~\bMlle\b\.?~u' => 'Mademoiselle',
        '~\bDr\b\.?'.self::NAME.'~u' => 'Docteur',
        '~\bPr\b\.?'.self::NAME.'~u' => 'Professeur',
        '~\bMe\b'.self::NAME.'~u' => 'Maître',
        '~\bSte\b\.?(?=[ \x{A0}-]+\p{Lu})~u' => 'Sainte',
        '~\bSt\b\.?(?=[ \x{A0}-]+\p{Lu})~u' => 'Saint',
        '~\bCie\b~u' => 'Compagnie',
        '~\b[nN]°[ \x{A0}]?(?=\d)~u' => 'numéro ',
        '~\bpp\.[ \x{A0}]?(?=\d)~u' => 'pages ',
        '~\bp\.[ \x{A0}]?(?=\d)~u' => 'page ',
        '~\bcf\.~iu' => 'confer',
        '~\benv\.(?=[ \x{A0}]+\d)~u' => 'environ',
        '~\bart\.[ \x{A0}]?(?=\d)~u' => 'article ',
        '~\bchap\.[ \x{A0}]?(?=\d)~u' => 'chapitre ',
        '~\bvol\.[ \x{A0}]?(?=\d)~u' => 'volume ',
        '~\bbd\b\.?'.self::NAME.'~u' => 'boulevard',
        '~\bav\.'.self::NAME.'~u' => 'avenue',
    ];

    private const array ENGLISH_ABBREVIATIONS = [
        '~\bMrs\b\.?'.self::NAME.'~u' => 'Missus',
        '~\bMr\b\.?'.self::NAME.'~u' => 'Mister',
        '~\bMs\b\.?'.self::NAME.'~u' => 'Miz',
        '~\bDr\.'.self::NAME.'~u' => 'Doctor',
        '~\bProf\.'.self::NAME.'~u' => 'Professor',
        '~\bSt\.'.self::NAME.'~u' => 'Saint',
        '~\bJr\.'.self::SENTENCE_END.'~u' => 'Junior.',
        '~\bJr\.~u' => 'Junior',
        '~\bSr\.'.self::SENTENCE_END.'~u' => 'Senior.',
        '~\bSr\.~u' => 'Senior',
        '~\bvs\.?(?=\s)~u' => 'versus',
        '~\be\.g\.(?=\s)~u' => 'for example',
        '~\bi\.e\.(?=\s)~u' => 'that is',
        '~\betc\.(?:\.\.)?'.self::SENTENCE_END.'~u' => 'et cetera.',
        '~\betc\.(?:\.\.)?~u' => 'et cetera',
        '~\bapprox\.~u' => 'approximately',
        '~\bNo\.[ \x{A0}]?(?=\d)~u' => 'number ',
        '~\bFig\.[ \x{A0}]?(?=\d)~u' => 'figure ',
        '~\bcf\.~u' => 'compare',
    ];

    /**
     * A plain integer, alone between spaces or before punctuation. Not a part of "A4", "5th", "3.5",
     * "3,5", "10:30", "COVID-19", "2024-05-12", "$42", "42 %" or "12 345".
     */
    private const string PLAIN_INTEGER = '~(?<![\w.,:/\-+$€£¥%°#@])(?<!\d )(\d+)(?![\w%°$€£¥]|[.,:/\-]\d| ?%| \d)~u';

    /**
     * "M. le maire": "Monsieur" before a lower-case word too (the first step only changes "M." before a name).
     */
    private const array FRENCH_SPEECH_ABBREVIATIONS = [
        '~\bMM\.(?= +\w)~u' => 'Messieurs',
        '~\bM\.(?= +[a-z\x{e0}-\x{ff}\x{153}\x{e6}])~u' => 'Monsieur',
    ];

    /**
     * @var array<string, NumberSpeller>
     */
    private array $spellers;

    public function __construct()
    {
        $spellers = [];
        foreach (Language::cases() as $language) {
            $spellers[$language->value] = new NumberSpeller($language);
        }
        $this->spellers = $spellers;
    }

    /**
     * The text for the voice engine: both steps.
     *
     * @param bool $spellIntegers writes plain integers in words (Phonon reads final digits badly)
     */
    public function normalize(string $text, Language $language, bool $spellIntegers = true): string
    {
        return $this->prepareForSpeech($this->writeOutSymbols($text, $language), $language, $spellIntegers);
    }

    /**
     * Step 1 (R-TX-5): numbers with decimals or thousands separators, `%`, `€`, French ordinals and
     * abbreviations in words. Plain integers ("2024", "42") stay as digits.
     */
    public function writeOutSymbols(string $text, Language $language): string
    {
        $speller = $this->speller($language);
        $french = Language::French === $language;
        $number = $french ? self::FRENCH_NUMBER : self::ENGLISH_NUMBER;

        $t = $text;
        foreach ($french ? self::FRENCH_ABBREVIATIONS : self::ENGLISH_ABBREVIATIONS as $pattern => $replacement) {
            $t = self::replace($pattern, $replacement, $t);
        }
        $t = self::replaceCallback('~'.$number.self::GAP.self::SCALE.'[ \x{A0}\x{2009}\x{202F}]?€~u', $t,
            static fn (array $g): string => $speller->money((string) $g[1], $g[2], $g[3]));
        $t = self::replaceCallback('~€'.self::GAP.$number.'(?:'.self::SCALE.'(?!\p{L})|'.self::WORD_SCALE.')~u', $t,
            static fn (array $g): string => $speller->money((string) $g[1], $g[2], $g[3] ?? $g[4]));
        $t = self::replaceCallback('~'.$number.self::GAP.'€~u', $t,
            static fn (array $g): string => $speller->money((string) $g[1], $g[2], null));
        $t = self::replaceCallback('~€'.self::GAP.$number.'~u', $t,
            static fn (array $g): string => $speller->money((string) $g[1], $g[2], null));
        $t = self::replaceCallback('~'.$number.self::GAP.'%~u', $t,
            static fn (array $g): string => $speller->number((string) $g[1], $g[2]).' '.$speller->percentWord());
        if ($french) {
            $t = self::replaceCallback(self::ARABIC_ORDINAL, $t,
                static fn (array $g): ?string => $speller->frenchOrdinal(NumberSpeller::integer((string) $g[1]), (string) $g[2]));
            $t = self::replaceCallback(self::ROMAN_ORDINAL, $t, static function (array $g) use ($speller): ?string {
                $roman = (string) $g[1];
                // A single L or C is a word ("Le", "Ce"), not a numeral.
                if (\strlen($roman) <= 1 && !\in_array($roman, ['I', 'V', 'X'], true)) {
                    return null;
                }

                return $speller->frenchOrdinal(NumberSpeller::romanValue($roman), (string) $g[2]);
            });
        }
        $t = self::replaceCallback('~'.$number.'~u', $t, static function (array $g) use ($speller): ?string {
            // Plain integers stay as digits.
            if (null === $g[2] && ctype_digit((string) $g[1])) {
                return null;
            }

            return $speller->number((string) $g[1], $g[2]);
        });
        $t = self::replace('~[ \x{A0}\x{2009}\x{202F}]?%~u', ' '.$speller->percentWord(), $t);
        $t = self::replace('~[ \x{A0}\x{2009}\x{202F}]?€~u', ' euros', $t);
        $t = self::replace('~[ \t]{2,}~u', ' ', $t);

        return self::replace('~^\h+|\h+$~u', '', $t);
    }

    /**
     * Step 2 (R-TS-16): whitespace collapsed, French "M."/"MM." before a lower-case word, and
     * plain integers in words when asked (English years from 1100 to 2099 as years).
     */
    public function prepareForSpeech(string $text, Language $language, bool $spellIntegers = true): string
    {
        $result = self::collapseWhitespace($text);
        if (Language::French === $language) {
            foreach (self::FRENCH_SPEECH_ABBREVIATIONS as $pattern => $replacement) {
                $result = self::replace($pattern, $replacement, $result);
            }
        }
        if ($spellIntegers) {
            $speller = $this->speller($language);
            $result = self::replaceCallback(self::PLAIN_INTEGER, $result,
                static fn (array $g): string => $speller->spellInteger((string) $g[1]));
        }

        return $result;
    }

    /**
     * Spaces, no-break spaces and line breaks become one space; none at the ends.
     */
    public static function collapseWhitespace(string $text): string
    {
        return trim(self::replace('~\s+~u', ' ', $text), ' ');
    }

    private function speller(Language $language): NumberSpeller
    {
        return $this->spellers[$language->value];
    }

    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        $result = preg_replace($pattern, $replacement, $subject);
        if (null === $result) {
            throw new \RuntimeException(\sprintf('Text normalization failed: %s.', preg_last_error_msg()));
        }

        return $result;
    }

    /**
     * @param callable(array<int|string, string|null>): ?string $transform returns null to keep the match
     */
    private static function replaceCallback(string $pattern, string $subject, callable $transform): string
    {
        $result = preg_replace_callback(
            $pattern,
            static fn (array $groups): string => $transform($groups) ?? (string) $groups[0],
            $subject,
            -1,
            $count,
            \PREG_UNMATCHED_AS_NULL,
        );
        if (null === $result) {
            throw new \RuntimeException(\sprintf('Text normalization failed: %s.', preg_last_error_msg()));
        }

        return $result;
    }
}

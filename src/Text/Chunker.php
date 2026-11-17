<?php

declare(strict_types=1);

namespace App\Text;

/**
 * Splits the sentences that are longer than the limit (R-TX-8) and halves a chunk the voice
 * engine refused (R-TX-10). Lengths count Unicode code points.
 *
 * Port of Chunker.swift, with one addition: the text is chunked as the article writes it and
 * normalized afterwards, so some spaces are not cut points, where a cut would hide a number, an
 * amount or an abbreviation from the normalizer: a no-break space (U+00A0, U+2007, U+202F), a
 * space between two digits ("12 000"), before `%` or `€` ("3,5 %"), and after a period inside a
 * sentence ("M. Bernard", "J. K. Rowling").
 */
final readonly class Chunker
{
    /**
     * The longest first chunk of the title and of the body (R-TX-7, R-PB-5): the first audio comes fast.
     */
    public const int FIRST_LIMIT = 120;

    private const array MARKS = [';' => true, ':' => true, ',' => true];
    private const array NO_BREAK_SPACES = ["\u{00A0}" => true, "\u{2007}" => true, "\u{202F}" => true];
    private const array BOUND_TO_NEXT = ['%' => true, '€' => true];

    /**
     * Splits a sentence into parts: the first one has at most $firstLimit characters, the others
     * at most $limit. Only spaces are lost.
     *
     * @return list<string>
     */
    public static function parts(string $sentence, int $firstLimit, int $limit): array
    {
        if ($firstLimit < 1 || $limit < 1) {
            throw new \InvalidArgumentException('The chunk limits must be positive.');
        }

        $rest = mb_str_split(mb_trim($sentence));
        $parts = [];
        $current = $firstLimit;
        while (\count($rest) > $current) {
            $count = \count($rest);
            // Parts of similar length: no short last part.
            $needed = intdiv($count + $limit - 1, $limit);
            $balanced = intdiv($count + $needed - 1, $needed) + intdiv($limit, 10);
            $cut = self::cutPosition($rest, $current < $limit ? $current : min($limit, $balanced));
            $head = mb_trim(implode('', \array_slice($rest, 0, $cut)));
            if ('' !== $head) {
                $parts[] = $head;
            }
            $start = $cut;
            while ($start < $count && self::isWhitespace($rest[$start])) {
                ++$start;
            }
            $rest = \array_slice($rest, $start);
            $current = $limit;
        }
        if ([] !== $rest) {
            $parts[] = implode('', $rest);
        }

        return $parts;
    }

    /**
     * Splits a text in two near its middle: after a `;`, `:` or `,` in the middle half if possible,
     * else at the cut space nearest the middle. Null when the text cannot be split.
     *
     * @return array{string, string}|null
     */
    public static function halves(string $text): ?array
    {
        $chars = mb_str_split(mb_trim($text));
        $count = \count($chars);
        if ($count <= 1) {
            return null;
        }

        $middle = intdiv($count, 2);
        $best = null;
        for ($i = 0; $i < $count - 1; ++$i) {
            $cut = $i + 1;
            if (isset(self::MARKS[$chars[$i]]) && self::isWhitespace($chars[$i + 1])
                && $cut >= intdiv($count, 4) && $cut <= intdiv($count * 3, 4)
                && (null === $best || abs($cut - $middle) < abs($best - $middle))) {
                $best = $cut;
            }
        }
        if (null === $best) {
            for ($i = 1; $i < $count; ++$i) {
                if (self::isCutSpace($chars, $i) && (null === $best || abs($i - $middle) < abs($best - $middle))) {
                    $best = $i;
                }
            }
        }
        if (null === $best) {
            return null;
        }

        $head = mb_trim(implode('', \array_slice($chars, 0, $best)));
        $tail = mb_trim(implode('', \array_slice($chars, $best)));

        return '' === $head || '' === $tail ? null : [$head, $tail];
    }

    /**
     * Number of characters of the first part. First choice: after the last `;`, `:` or `,` in the
     * last two thirds of the limit; then after any of these marks; then at the last cut space;
     * then a hard cut (a word longer than the limit).
     *
     * @param list<string> $chars
     */
    private static function cutPosition(array $chars, int $limit): int
    {
        $upper = min($limit, \count($chars) - 1);
        $lastMark = $lastMarkInRange = null;
        for ($i = 1; $i < max(1, $upper); ++$i) {
            if (isset(self::MARKS[$chars[$i]]) && self::isWhitespace($chars[$i + 1])) {
                $lastMark = $i;
                if ($i >= intdiv($limit, 3)) {
                    $lastMarkInRange = $i;
                }
            }
        }
        $mark = $lastMarkInRange ?? $lastMark;
        if (null !== $mark) {
            return $mark + 1;
        }
        for ($i = max(1, $upper); $i >= 1; --$i) {
            if (self::isCutSpace($chars, $i)) {
                return $i;
            }
        }

        return $limit;
    }

    /**
     * @param list<string> $chars
     */
    private static function isCutSpace(array $chars, int $i): bool
    {
        $char = $chars[$i];
        if (!self::isWhitespace($char) || isset(self::NO_BREAK_SPACES[$char])) {
            return false;
        }

        $previous = $chars[$i - 1] ?? '';
        $next = $chars[$i + 1] ?? '';

        return '.' !== $previous && !isset(self::BOUND_TO_NEXT[$next]) && !(ctype_digit($previous) && ctype_digit($next));
    }

    private static function isWhitespace(string $char): bool
    {
        return true === \IntlChar::isUWhiteSpace($char);
    }
}

<?php

declare(strict_types=1);

namespace App\Text;

/**
 * Splits a text into sentences with the ICU sentence break iterator of the language (R-TX-6).
 * Joined with spaces, the sentences give back the text, except whitespace.
 */
final readonly class SentenceSplitter
{
    private const array FRENCH_NON_TERMINAL = [
        'M.', 'MM.', 'Mme.', 'Mlle.', 'Dr.', 'Pr.', 'Me.', 'St.', 'Ste.', 'p.', 'pp.', 'cf.', 'Cf.',
        'av.', 'apr.', 'env.', 'art.', 'chap.', 'vol.', 'bd.', 'n°', 'N°', 'c.-à-d.',
    ];

    private const array ENGLISH_NON_TERMINAL = [
        'Mr.', 'Mrs.', 'Ms.', 'Dr.', 'Prof.', 'St.', 'Mt.', 'Gen.', 'Gov.', 'Sen.', 'Rep.', 'Lt.',
        'Col.', 'Capt.', 'Sgt.', 'No.', 'Fig.', 'vs.', 'e.g.', 'i.e.', 'approx.', 'cf.', 'vol.', 'p.', 'pp.',
    ];

    /**
     * Closing quotes and brackets at the start of a piece, with the spaces before them.
     */
    private const string LEADING_CLOSERS = '/^\s*[»”’)\]]+/u';

    /**
     * @return list<string>
     */
    public function split(string $text, Language $language): array
    {
        if ('' === $text) {
            return [];
        }

        $iterator = \IntlBreakIterator::createSentenceInstance($language->locale());
        if (null === $iterator) {
            throw new \RuntimeException(\sprintf('No sentence break iterator for %s.', $language->locale()));
        }
        $iterator->setText($text);

        // The iterator gives byte offsets in the UTF-8 text.
        $nonTerminal = Language::French === $language ? self::FRENCH_NON_TERMINAL : self::ENGLISH_NON_TERMINAL;
        $pieces = [];
        $start = $iterator->first();
        while (\IntlBreakIterator::DONE !== ($end = $iterator->next())) {
            $piece = substr($text, $start, $end - $start);
            $last = array_key_last($pieces);
            // French spacing: ICU breaks before the "»" of "« Où ça ? »"; the quote closes the sentence.
            if (null !== $last && 1 === preg_match(self::LEADING_CLOSERS, $piece, $closers)) {
                $pieces[$last] .= $closers[0];
                $piece = substr($piece, \strlen($closers[0]));
            }
            if (null !== $last && self::endsWithNonTerminal($pieces[$last], $nonTerminal)) {
                $pieces[$last] .= $piece;
            } else {
                $pieces[] = $piece;
            }
            $start = $end;
        }

        $sentences = [];
        foreach ($pieces as $piece) {
            $piece = mb_trim($piece);
            if ('' !== $piece) {
                $sentences[] = $piece;
            }
        }

        return $sentences;
    }

    /**
     * True when the piece ends with an abbreviation such as "Dr." or an initial such as "J.".
     *
     * @param list<string> $nonTerminal
     */
    private static function endsWithNonTerminal(string $piece, array $nonTerminal): bool
    {
        $words = preg_split('/\s+/u', mb_trim($piece), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $words || [] === $words) {
            return false;
        }
        $token = (string) preg_replace('/^\P{L}+/u', '', $words[array_key_last($words)]);
        if (\in_array($token, $nonTerminal, true)) {
            return true;
        }

        return 2 === mb_strlen($token) && 1 === preg_match('/^\p{Lu}\.$/u', $token);
    }
}

<?php

declare(strict_types=1);

namespace App\Text;

/**
 * Text preparation (SPEC 5.4): blocks to chunks, each with the text as the article writes it and
 * the text for the voice engine.
 *
 * Port of ArticlePreparer.swift. The Swift app normalized a paragraph, then split it; here the
 * display text is split into sentences and chunks, then each chunk is normalized, so that the
 * read-along view shows the words of the article. The chunk limits apply to the display text.
 */
final readonly class ArticlePreparer
{
    /**
     * Below this number of body words, there is no readable text (R-EX-5).
     */
    public const int MINIMUM_WORD_COUNT = 30;

    /**
     * Silences after a chunk (R-TX-4).
     */
    public const int PARAGRAPH_PAUSE_MS = 700;
    public const int SENTENCE_PAUSE_MS = 250;
    public const int INSIDE_SENTENCE_PAUSE_MS = 0;

    public function __construct(
        private SentenceSplitter $splitter = new SentenceSplitter(),
        private TextNormalizer $normalizer = new TextNormalizer(),
    ) {
    }

    /**
     * The title is paragraph 0 when it has a word. A first heading that repeats the title is
     * dropped. Consecutive list items form one paragraph, as a Markdown list did in the Swift app
     * (250 ms between the items, 700 ms after the list).
     *
     * @param list<Block> $blocks
     *
     * @throws NoReadableText under 30 words of body
     */
    public function prepare(string $title, array $blocks, Language $language, bool $spellIntegers = true): PreparedArticle
    {
        $title = self::collapse($title);
        $blocks = array_values(array_filter(
            array_map(static fn (Block $block): Block => new Block($block->kind, self::collapse($block->text)), $blocks),
            static fn (Block $block): bool => self::hasWord($block->text),
        ));
        if ([] !== $blocks && BlockKind::Heading === $blocks[0]->kind && self::comparable($blocks[0]->text) === self::comparable($title)) {
            array_shift($blocks);
        }

        $bodyWords = array_sum(array_map(static fn (Block $block): int => self::countWords($block->text), $blocks));
        if ($bodyWords < self::MINIMUM_WORD_COUNT) {
            throw new NoReadableText($bodyWords);
        }

        $startsWithTitle = self::hasWord($title);
        $paragraphs = self::paragraphs($startsWithTitle ? $title : null, $blocks);
        $limit = $language->chunkLimit();
        $segments = [];
        $paragraphIndex = 0;
        $wordCount = 0;
        foreach ($paragraphs as $p => [$kind, $texts]) {
            $isFirst = 0 === $p || (1 === $p && $startsWithTitle);
            /** @var list<array{string, int}> $chunks */
            $chunks = [];
            foreach ($texts as $text) {
                foreach ($this->splitter->split($text, $language) as $sentence) {
                    $firstLimit = $isFirst && [] === $chunks ? Chunker::FIRST_LIMIT : $limit;
                    $parts = array_values(array_filter(Chunker::parts($sentence, $firstLimit, $limit), self::hasWord(...)));
                    foreach ($parts as $k => $part) {
                        $chunks[] = [$part, $k === \count($parts) - 1 ? self::SENTENCE_PAUSE_MS : self::INSIDE_SENTENCE_PAUSE_MS];
                    }
                }
            }
            if ([] === $chunks) {
                continue;
            }
            $last = \count($chunks) - 1;
            foreach ($chunks as $k => [$text, $pause]) {
                $segments[] = new PreparedSegment(
                    $paragraphIndex,
                    $kind,
                    $text,
                    $this->normalizer->normalize($text, $language, $spellIntegers),
                    $k === $last ? self::PARAGRAPH_PAUSE_MS : $pause,
                );
                $wordCount += self::countWords($text);
            }
            ++$paragraphIndex;
        }

        return new PreparedArticle($title, $language, $segments, $paragraphIndex, $wordCount);
    }

    /**
     * Splits a chunk in two near its middle (R-TX-10), at `;`, `:` or `,` if possible, else at a
     * space. The display text and the spoken text are halved each on their own: the spoken text
     * may have been rewritten. Returns the segment unchanged when either text cannot be split.
     *
     * @return list<PreparedSegment>
     */
    public static function split(PreparedSegment $segment): array
    {
        $text = Chunker::halves($segment->text);
        $spoken = Chunker::halves($segment->spoken);
        if (null === $text || null === $spoken) {
            return [$segment];
        }

        return [
            new PreparedSegment($segment->paragraph, $segment->kind, $text[0], $spoken[0], self::INSIDE_SENTENCE_PAUSE_MS),
            new PreparedSegment($segment->paragraph, $segment->kind, $text[1], $spoken[1], $segment->pauseAfterMs),
        ];
    }

    /**
     * Words are whitespace separated tokens with at least one letter or digit.
     */
    public static function countWords(string $text): int
    {
        $count = 0;
        foreach (preg_split('/\s+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (self::hasWord($token)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<Block> $blocks
     *
     * @return list<array{BlockKind, list<string>}>
     */
    private static function paragraphs(?string $title, array $blocks): array
    {
        $paragraphs = null === $title ? [] : [[BlockKind::Title, [$title]]];
        $items = [];
        foreach ($blocks as $block) {
            if (BlockKind::ListItem === $block->kind) {
                $items[] = $block->text;
                continue;
            }
            if ([] !== $items) {
                $paragraphs[] = [BlockKind::ListItem, $items];
                $items = [];
            }
            $paragraphs[] = [$block->kind, [$block->text]];
        }
        if ([] !== $items) {
            $paragraphs[] = [BlockKind::ListItem, $items];
        }

        return $paragraphs;
    }

    /**
     * Spaces and line breaks become one space. No-break spaces stay: they bind numbers and units.
     */
    private static function collapse(string $text): string
    {
        return mb_trim((string) preg_replace('/[ \t\n\r\f\v]+/', ' ', $text));
    }

    private static function hasWord(string $text): bool
    {
        return 1 === preg_match('/[\p{L}\p{N}]/u', $text);
    }

    private static function comparable(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtolower($text));
    }
}

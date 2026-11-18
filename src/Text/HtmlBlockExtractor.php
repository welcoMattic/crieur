<?php

declare(strict_types=1);

namespace App\Text;

/**
 * Turns the article HTML that Defuddle returns (on the server or in the browser extension) into readable blocks (R-TX-1, R-TX-2):
 * headings, paragraphs, quotes and list items, as plain text.
 *
 * Dropped: images, figure captions, media, scripts and styles, code blocks (`pre`), navigation,
 * forms, hidden elements, footnote marks and footnote definitions (as the Swift app dropped
 * them from the Markdown), bare URLs and e-mail addresses (a link keeps its text). A figure is
 * read for what it holds besides its image and caption (a table, a quote). A table becomes one
 * paragraph with one sentence per row, cells joined with commas: "Ligne, Fréquence."
 */
final readonly class HtmlBlockExtractor
{
    private const array SKIPPED = [
        'audio', 'button', 'canvas', 'caption', 'datalist', 'dialog', 'embed', 'figcaption', 'head', 'iframe',
        'img', 'input', 'link', 'map', 'math', 'meta', 'nav', 'noscript', 'object', 'option', 'picture', 'pre',
        'rp', 'rt', 'script', 'select', 'source', 'style', 'svg', 'template', 'textarea', 'title', 'track', 'video',
    ];

    private const array BLOCKS = [
        'address', 'article', 'aside', 'blockquote', 'body', 'center', 'dd', 'details', 'div', 'dl', 'dt', 'fieldset',
        'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr', 'html', 'legend',
        'li', 'main', 'menu', 'ol', 'p', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    ];

    private const array HEADINGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    /**
     * Page furniture that an extractor may keep inside the article: Wikipedia hatnotes, infoboxes,
     * edit links and reference marks, screen reader only text.
     */
    public const array BOILERPLATE_CLASSES = [
        'ambox', 'catlinks', 'hatnote', 'infobox', 'metadata', 'mw-editsection', 'mw-jump-link', 'navbox',
        'noprint', 'reference', 'screen-reader-text', 'shortdescription', 'sidebar', 'sr-only', 'thumbcaption',
        'toc', 'vertical-navbox', 'visually-hidden',
    ];

    /**
     * The notes at the end of an article (ARIA roles of pandoc, markdown-it, EPUB).
     */
    private const array NOTE_ROLES = ['doc-endnotes', 'doc-endnote', 'doc-footnote', 'doc-backlink', 'doc-noteref'];

    /**
     * The id of a footnote definition: "fn1", "fn:2", "footnote-3", "cite_note-4".
     */
    private const string NOTE_ID = '~^(?:fn|footnote|endnote|cite_note)[-:_.]?\w*\d~i';

    /**
     * A footnote mark: "1", "[1]", "(2)", "1, 3", "*". Letters stay: "XIX<sup>e</sup>", "1<sup>er</sup>".
     */
    private const string FOOTNOTE_MARK = '~^\s*[\[(]?\s*(?:\d{1,3}|[*\x{2020}\x{2021}])(?:\s*[,\x{2013}-]\s*\d{1,3})*\s*[\])]?\s*$~u';

    /**
     * Cleaning of the text of a block, in this order (the rules of MarkdownCleaner.swift).
     */
    private const array CLEANING = [
        '~[\x{00AD}\x{200B}\x{200C}\x{200D}\x{2060}\x{FEFF}]~u' => '',
        '~\bmailto:[^\s<>"]*[^\s<>".,;:!?\'’»)\]]~iu' => '',
        '~\b(?:https?|ftp)://[^\s<>"]*[^\s<>".,;:!?\'’»)\]]~iu' => '',
        '~\bwww\.[^\s<>"]*[^\s<>".,;:!?\'’»)\]]~iu' => '',
        '~[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}~u' => '',
        '~\[\d{1,3}\]~u' => '',
        '~\(\s*[,;:]?\s*\)|\[\s*\]~u' => '',
        '~[ \t\n\r\f]+~u' => ' ',
        '~ +([.,])(?!\d)~u' => '$1',
    ];

    /**
     * @return list<Block>
     */
    public function extract(string $html): array
    {
        if ('' === trim($html)) {
            return [];
        }

        $document = \Dom\HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$html.'</body></html>',
            \LIBXML_NOERROR,
            'UTF-8',
        );
        if (null === $document->body) {
            return [];
        }

        $blocks = [];
        $buffer = '';
        $this->walk($document->body, BlockKind::Paragraph, $buffer, $blocks);
        self::flush($buffer, BlockKind::Paragraph, $blocks);

        return $blocks;
    }

    /**
     * Collects the inline text of $parent in $buffer and emits a block at each block boundary.
     *
     * @param list<Block> $blocks
     *
     * @param-out list<Block> $blocks
     */
    private function walk(\Dom\Node $parent, BlockKind $kind, string &$buffer, array &$blocks): void
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof \Dom\Text) {
                $buffer .= $node->data;
                continue;
            }
            if (!$node instanceof \Dom\Element || self::isSkipped($node)) {
                continue;
            }

            $name = $node->localName;
            if ('br' === $name) {
                $buffer .= ' ';
                continue;
            }
            if (!\in_array($name, self::BLOCKS, true)) {
                $this->walk($node, $kind, $buffer, $blocks);
                continue;
            }

            self::flush($buffer, $kind, $blocks);
            if ('table' === $name) {
                $rows = $this->tableText($node);
                self::flush($rows, BlockKind::Heading === $kind ? BlockKind::Paragraph : $kind, $blocks);
                continue;
            }
            $childKind = match (true) {
                \in_array($name, self::HEADINGS, true) => BlockKind::Heading,
                'li' === $name => BlockKind::ListItem,
                'blockquote' === $name => BlockKind::Quote,
                default => $kind,
            };
            $this->walk($node, $childKind, $buffer, $blocks);
            self::flush($buffer, $childKind, $blocks);
        }
    }

    /**
     * One sentence per row, cells joined with commas.
     */
    private function tableText(\Dom\Element $table): string
    {
        $rows = [];
        foreach ($table->childNodes as $child) {
            if (!$child instanceof \Dom\Element) {
                continue;
            }
            $rowElements = 'tr' === $child->localName ? [$child] : (\in_array($child->localName, ['thead', 'tbody', 'tfoot'], true) ? $child->childNodes : []);
            foreach ($rowElements as $row) {
                if (!$row instanceof \Dom\Element || 'tr' !== $row->localName) {
                    continue;
                }
                $cells = [];
                foreach ($row->childNodes as $cell) {
                    if ($cell instanceof \Dom\Element && \in_array($cell->localName, ['td', 'th'], true)) {
                        $text = self::clean($this->plainText($cell));
                        if ('' !== $text) {
                            $cells[] = $text;
                        }
                    }
                }
                if ([] !== $cells) {
                    $line = implode(', ', $cells);
                    $rows[] = 1 === preg_match('~[.!?\x{2026}:;]$~u', $line) ? $line : $line.'.';
                }
            }
        }

        return implode(' ', $rows);
    }

    /**
     * The text of an element with its blocks separated by spaces (a table cell).
     */
    private function plainText(\Dom\Element $element): string
    {
        $blocks = [];
        $buffer = '';
        $this->walk($element, BlockKind::Paragraph, $buffer, $blocks);

        return implode(' ', [...array_map(static fn (Block $block): string => $block->text, $blocks), $buffer]);
    }

    private static function isSkipped(\Dom\Element $element): bool
    {
        $name = $element->localName;
        if (\in_array($name, self::SKIPPED, true)
            || $element->hasAttribute('hidden')
            || 'true' === strtolower((string) $element->getAttribute('aria-hidden'))
            || \in_array(strtolower((string) $element->getAttribute('role')), [...self::NOTE_ROLES, 'note'], true)
            || [] !== array_intersect(preg_split('~\s+~', strtolower((string) $element->getAttribute('class'))) ?: [], self::BOILERPLATE_CLASSES)
            || ('li' === $name && 1 === preg_match(self::NOTE_ID, (string) $element->getAttribute('id')))) {
            return true;
        }

        $text = (string) $element->textContent;

        // A footnote mark: <sup>1</sup>, <sup><a href="#fn1">[1]</a></sup>, <a href="#note-2">2</a>.
        return match ($name) {
            'sup' => 1 === preg_match(self::FOOTNOTE_MARK, $text),
            'a', 'span' => 1 === preg_match('~^\s*\[\d{1,3}\]\s*$~u', $text)
                || ('a' === $name && str_starts_with((string) $element->getAttribute('href'), '#') && 1 === preg_match(self::FOOTNOTE_MARK, $text)),
            default => false,
        };
    }

    /**
     * Emits the buffer as a block when it holds a word, then empties it.
     *
     * @param list<Block> $blocks
     *
     * @param-out list<Block> $blocks
     */
    private static function flush(string &$buffer, BlockKind $kind, array &$blocks): void
    {
        $text = self::clean($buffer);
        $buffer = '';
        if (1 === preg_match('~[\p{L}\p{N}]~u', $text)) {
            $blocks[] = new Block($kind, $text);
        }
    }

    private static function clean(string $text): string
    {
        foreach (self::CLEANING as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return mb_trim($text);
    }
}

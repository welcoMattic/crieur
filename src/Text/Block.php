<?php

declare(strict_types=1);

namespace App\Text;

/**
 * One readable block of an article: a heading, a paragraph, a quote or a list item, as plain text.
 */
final readonly class Block
{
    public function __construct(
        public BlockKind $kind,
        public string $text,
    ) {
    }
}

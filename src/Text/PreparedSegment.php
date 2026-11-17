<?php

declare(strict_types=1);

namespace App\Text;

/**
 * One chunk of an article: the text shown to the listener and the text the voice engine reads.
 */
final readonly class PreparedSegment
{
    public function __construct(
        public int $paragraph,
        public BlockKind $kind,
        public string $text,
        public string $spoken,
        public int $pauseAfterMs,
    ) {
    }
}

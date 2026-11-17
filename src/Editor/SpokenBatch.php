<?php

declare(strict_types=1);

namespace App\Editor;

/**
 * The structured answer of the speech editor for one batch of chunks.
 */
final class SpokenBatch
{
    /**
     * @param list<SpokenChunk> $chunks
     */
    public function __construct(
        public array $chunks = [],
        public ?string $summary = null,
    ) {
    }
}

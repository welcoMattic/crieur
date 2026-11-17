<?php

declare(strict_types=1);

namespace App\Editor;

/**
 * The text a voice must read for one chunk, as the speech editor wrote it.
 */
final class SpokenChunk
{
    public function __construct(
        public int $id = 0,
        public string $spoken = '',
    ) {
    }
}

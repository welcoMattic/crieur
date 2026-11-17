<?php

declare(strict_types=1);

namespace App\Editor;

final readonly class EditedBatch
{
    /**
     * @param array<int, string> $spoken the text to read, by chunk id, for the chunks the editor rewrote faithfully
     */
    public function __construct(
        public array $spoken,
        public ?string $summary,
    ) {
    }
}

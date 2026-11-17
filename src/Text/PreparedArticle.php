<?php

declare(strict_types=1);

namespace App\Text;

final readonly class PreparedArticle
{
    /**
     * @param list<PreparedSegment> $segments the title first (paragraph 0), then the body
     */
    public function __construct(
        public string $title,
        public Language $language,
        public array $segments,
        public int $paragraphCount,
        public int $wordCount,
    ) {
    }
}

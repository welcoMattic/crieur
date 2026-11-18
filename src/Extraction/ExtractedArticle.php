<?php

declare(strict_types=1);

namespace App\Extraction;

/**
 * The main content of a web page and its metadata, as Defuddle found them.
 */
final readonly class ExtractedArticle
{
    public function __construct(
        public string $url,
        public string $title,
        public string $html,
        public ?string $siteName = null,
        public ?string $author = null,
        public ?string $excerpt = null,
        public ?string $imageUrl = null,
        public ?string $languageHint = null,
        public ?\DateTimeImmutable $publishedAt = null,
    ) {
    }
}

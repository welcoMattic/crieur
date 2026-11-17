<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Fetch the page, extract the article and prepare its text for the ear.
 */
final readonly class ExtractArticle
{
    public function __construct(
        public Uuid $articleId,
    ) {
    }
}

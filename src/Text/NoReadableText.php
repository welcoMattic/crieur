<?php

declare(strict_types=1);

namespace App\Text;

/**
 * The article has fewer words than a listener needs (R-EX-5).
 */
final class NoReadableText extends \RuntimeException
{
    public function __construct(
        public readonly int $wordCount,
        public readonly int $minimumWordCount = ArticlePreparer::MINIMUM_WORD_COUNT,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('No readable text: %d words, at least %d needed.', $wordCount, $minimumWordCount), 0, $previous);
    }
}

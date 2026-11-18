<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * The public demo takes no more articles from this visitor, or from anyone, for now.
 */
final class TooManyArticles extends \RuntimeException
{
    public function __construct(
        public readonly \DateTimeImmutable $retryAfter,
    ) {
        parent::__construct(\sprintf('The public demo takes more articles from %s.', $retryAfter->format(\DATE_ATOM)));
    }

    public function retryAfterSeconds(\DateTimeImmutable $now): int
    {
        return max(1, $this->retryAfter->getTimestamp() - $now->getTimestamp());
    }
}

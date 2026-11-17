<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Synthesize the chunks of an article that have no audio yet, in reading order.
 *
 * The revision is the one of the article when the message was sent: a run for an older revision
 * stops at once, because the voice or the text changed in the meantime.
 */
final readonly class SynthesizeArticle
{
    public function __construct(
        public Uuid $articleId,
        public int $revision,
    ) {
    }
}

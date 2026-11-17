<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

/**
 * Rewrite the chunks of an article for the ear with the speech editor, ahead of the voice.
 *
 * It runs in its own worker: the synthesis never waits for a whole batch, it only waits a few
 * seconds for the chunk it is about to read, then reads the text of the rules.
 */
final readonly class EditArticle
{
    public function __construct(
        public Uuid $articleId,
        public int $revision,
    ) {
    }
}

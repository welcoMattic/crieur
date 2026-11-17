<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Where an article stands in the pipeline: queued, then extracted, prepared for the ear,
 * synthesized chunk after chunk, and finally ready to be listened to from start to end.
 */
enum ArticleStatus: string
{
    case Queued = 'queued';
    case Extracting = 'extracting';
    case Preparing = 'preparing';
    case Synthesizing = 'synthesizing';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isInProgress(): bool
    {
        return match ($this) {
            self::Queued, self::Extracting, self::Preparing, self::Synthesizing => true,
            self::Ready, self::Failed => false,
        };
    }

    /**
     * Audio can play as soon as the first chunks exist, long before the whole article is ready.
     */
    public function isPlayable(): bool
    {
        return self::Synthesizing === $this || self::Ready === $this;
    }
}

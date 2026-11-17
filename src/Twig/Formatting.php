<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\ArticleStatus;
use Twig\Attribute\AsTwigFilter;

/**
 * Small formatting helpers for the templates: durations, status icons, text fingerprints.
 */
final class Formatting
{
    /**
     * "4:05", or "1:02:09" past an hour.
     */
    #[AsTwigFilter('duration')]
    public function duration(float|int|null $seconds): string
    {
        $total = max(0, (int) round($seconds ?? 0));
        $hours = intdiv($total, 3600);
        $minutes = intdiv($total % 3600, 60);

        return $hours > 0
            ? \sprintf('%d:%02d:%02d', $hours, $minutes, $total % 60)
            : \sprintf('%d:%02d', $minutes, $total % 60);
    }

    #[AsTwigFilter('status_icon')]
    public function statusIcon(ArticleStatus $status): string
    {
        return match ($status) {
            ArticleStatus::Queued => 'clock',
            ArticleStatus::Extracting, ArticleStatus::Preparing, ArticleStatus::Synthesizing => 'progress',
            ArticleStatus::Ready => 'check-circle',
            ArticleStatus::Failed => 'alert',
        };
    }

    #[AsTwigFilter('spoken_hash')]
    public function spokenHashFilter(string $spoken): string
    {
        return self::spokenHash($spoken);
    }

    /**
     * A short fingerprint of the spoken text of a chunk: the read-along page compares it with the
     * status to show the text the speech editor rewrote while the page is open.
     */
    public static function spokenHash(string $spoken): string
    {
        return substr(hash('xxh3', $spoken), 0, 12);
    }
}

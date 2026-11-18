<?php

declare(strict_types=1);

namespace App\Twig;

use App\Demo\PublicDemo;
use Twig\Attribute\AsTwigFunction;

/**
 * What the home page of the public demo tells its visitors: when the library is emptied, and
 * how much each visitor may add.
 */
final readonly class Demo
{
    public function __construct(
        private PublicDemo $demo,
    ) {
    }

    /**
     * Null anywhere but on the public demo.
     *
     * @return array{next: \DateTimeImmutable, hours: int, minutes: int, articles: int, characters: int}|null
     */
    #[AsTwigFunction('public_demo')]
    public function publicDemo(): ?array
    {
        if (!$this->demo->isEnabled()) {
            return null;
        }

        $minutes = $this->demo->minutesUntilReset();

        return [
            'next' => $this->demo->nextReset(),
            'hours' => intdiv($minutes, 60),
            'minutes' => $minutes % 60,
            'articles' => $this->demo->visitorArticles,
            'characters' => $this->demo->maxCharacters,
        ];
    }
}

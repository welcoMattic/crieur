<?php

declare(strict_types=1);

namespace App\Twig;

use App\Demo\PublicDemo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;

/**
 * What the home page of the public demo tells its visitors: when the library is emptied, and
 * how much each visitor may add.
 */
final readonly class Demo
{
    public function __construct(
        private PublicDemo $demo,
        // HTML of the operator for the <head> of the public demo only (an analytics script, say),
        // kept in the environment of the host rather than in the repository.
        #[Autowire(env: 'CRIEUR_DEMO_HEAD')]
        private string $head = '',
    ) {
    }

    /**
     * Null anywhere but on the public demo.
     *
     * @return array{next: \DateTimeImmutable, hours: int, minutes: int, articles: int, characters: int, head: string}|null
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
            'head' => $this->head,
        ];
    }
}

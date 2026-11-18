<?php

declare(strict_types=1);

namespace App\Demo;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

/**
 * The public demo (APP_ENV=demo): one library shared by every visitor, emptied every six hours,
 * and a few articles an hour per visitor. Elsewhere, nothing here applies.
 *
 * The library is emptied at fixed times, every six hours from midnight UTC: one trigger for the
 * schedule that empties it (DemoSchedule) and for the home page that announces it.
 */
final readonly class PublicDemo
{
    public const int RESET_SECONDS = 6 * 3600;

    public function __construct(
        #[Autowire(param: 'crieur.public_demo')]
        private bool $enabled,
        #[Target('demo_visitor')]
        private RateLimiterFactoryInterface $visitorLimiter,
        #[Target('demo_all')]
        private RateLimiterFactoryInterface $allLimiter,
        private RequestStack $requests,
        private ClockInterface $clock,
        /** How many articles a visitor may add in an hour. */
        #[Autowire(param: 'crieur.demo.visitor_articles')]
        public int $visitorArticles = 5,
        /** The longest article read, in characters of text. */
        #[Autowire(param: 'crieur.demo.max_characters')]
        public int $maxCharacters = 25_000,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Every six hours from midnight UTC.
     */
    public static function resetTrigger(): PeriodicalTrigger
    {
        return new PeriodicalTrigger(self::RESET_SECONDS, new \DateTimeImmutable('1970-01-01 00:00:00', new \DateTimeZone('UTC')));
    }

    /**
     * The next time the library is emptied.
     */
    public function nextReset(?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $next = self::resetTrigger()->getNextRunDate($now ?? $this->clock->now()) ?? throw new \LogicException('The demo is emptied for ever.');

        return $next->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * How long until the library is emptied, in whole minutes, at least one.
     */
    public function minutesUntilReset(): int
    {
        $now = $this->clock->now();

        return max(1, (int) ceil(($this->nextReset($now)->getTimestamp() - $now->getTimestamp()) / 60));
    }

    /**
     * Counts one more article for the visitor of the current request, and for the demo as a
     * whole. Commands and workers, without a request, are never limited.
     *
     * @throws TooManyArticles
     */
    public function consumeArticle(): void
    {
        $request = $this->requests->getMainRequest();
        if (!$this->enabled || null === $request) {
            return;
        }

        // The visitor first: one visitor alone never uses up what the others may add.
        foreach ([$this->visitorLimiter->create($request->getClientIp() ?? 'unknown'), $this->allLimiter->create('all')] as $limiter) {
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                throw new TooManyArticles($limit->getRetryAfter());
            }
        }
    }

    /**
     * Whether an article of this many characters is too long for the demo.
     */
    public function isTooLong(int $characters): bool
    {
        return $this->enabled && $characters > $this->maxCharacters;
    }
}

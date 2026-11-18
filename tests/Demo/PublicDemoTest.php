<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Demo\PublicDemo;
use App\Demo\TooManyArticles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class PublicDemoTest extends TestCase
{
    #[Test]
    public function theLibraryIsEmptiedEverySixHoursFromMidnightUtc(): void
    {
        $demo = self::demo(true, new RequestStack());

        static::assertSame('2026-11-18 18:00', $demo->nextReset(new \DateTimeImmutable('2026-11-18 17:59:59 UTC'))->format('Y-m-d H:i'));
        static::assertSame('2026-11-19 00:00', $demo->nextReset(new \DateTimeImmutable('2026-11-18 18:00:00 UTC'))->format('Y-m-d H:i'));
        // Whatever the time zone of the server.
        static::assertSame('2026-11-18 12:00 UTC', $demo->nextReset(new \DateTimeImmutable('2026-11-18 08:30:00', new \DateTimeZone('Europe/Paris')))->format('Y-m-d H:i T'));
    }

    #[Test]
    public function aVisitorMayAddAFewArticlesAnHour(): void
    {
        $demo = self::demo(true, self::requestFrom('203.0.113.7'), visitor: 2);
        $demo->consumeArticle();
        $demo->consumeArticle();

        try {
            $demo->consumeArticle();
            static::fail('The third article of the hour is refused.');
        } catch (TooManyArticles $e) {
            static::assertGreaterThan(new \DateTimeImmutable(), $e->retryAfter);
        }
    }

    #[Test]
    public function everyVisitorTogetherMayAddOnlySoMany(): void
    {
        $storage = new InMemoryStorage();
        self::demo(true, self::requestFrom('203.0.113.7'), visitor: 5, all: 3, storage: $storage)->consumeArticle();
        self::demo(true, self::requestFrom('203.0.113.8'), visitor: 5, all: 3, storage: $storage)->consumeArticle();
        self::demo(true, self::requestFrom('203.0.113.9'), visitor: 5, all: 3, storage: $storage)->consumeArticle();

        $this->expectException(TooManyArticles::class);
        self::demo(true, self::requestFrom('203.0.113.10'), visitor: 5, all: 3, storage: $storage)->consumeArticle();
    }

    #[Test]
    public function nothingIsLimitedOutsideTheDemoNorWithoutARequest(): void
    {
        $outside = self::demo(false, self::requestFrom('203.0.113.7'), visitor: 1);
        $commands = self::demo(true, new RequestStack(), visitor: 1);
        for ($i = 0; $i < 3; ++$i) {
            $outside->consumeArticle();
            $commands->consumeArticle();
        }

        static::assertFalse($outside->isTooLong(1_000_000));
        static::assertTrue($commands->isTooLong(25_001));
        static::assertFalse($commands->isTooLong(25_000));
    }

    private static function requestFrom(string $ip): RequestStack
    {
        $requests = new RequestStack();
        $requests->push(Request::create('/articles', 'POST', server: ['REMOTE_ADDR' => $ip]));

        return $requests;
    }

    private static function demo(bool $enabled, RequestStack $requests, int $visitor = 5, int $all = 30, ?InMemoryStorage $storage = null): PublicDemo
    {
        $storage ??= new InMemoryStorage();

        return new PublicDemo(
            $enabled,
            new RateLimiterFactory(['id' => 'demo_visitor', 'policy' => 'sliding_window', 'limit' => $visitor, 'interval' => '1 hour'], $storage),
            new RateLimiterFactory(['id' => 'demo_all', 'policy' => 'sliding_window', 'limit' => $all, 'interval' => '1 hour'], $storage),
            $requests,
            new MockClock(),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Demo\DemoSchedule;
use App\Demo\LibraryReset;
use App\Message\ResetDemoLibrary;
use App\MessageHandler\ResetDemoLibraryHandler;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Scheduler\Generator\MessageGenerator;

final class DemoScheduleTest extends DemoTestCase
{
    #[Test]
    public function theScheduleSendsTheResetAtTheTimeTheHomePageAnnounces(): void
    {
        $clock = new MockClock('2026-11-18 17:58:30 UTC');
        $generator = new MessageGenerator(new DemoSchedule(new ArrayAdapter()), 'demo', $clock);
        $announced = self::demo(true, $clock)->nextReset();
        static::assertSame('2026-11-18 18:00 UTC', $announced->format('Y-m-d H:i T'));

        // The worker of the schedule asks every second or so: nothing before the announced time.
        $sent = [];
        while ($clock->now() < new \DateTimeImmutable('2026-11-18 18:10:00 UTC')) {
            foreach ($generator->getMessages() as $context => $message) {
                static::assertInstanceOf(ResetDemoLibrary::class, $message);
                $sent[] = $context->triggeredAt->format('H:i:s');
            }
            $clock->sleep(1);
        }

        static::assertSame(['18:00:00'], $sent);
    }

    #[Test]
    public function theResetEmptiesTheDemoOnly(): void
    {
        $demo = self::library(3);
        new ResetDemoLibraryHandler(self::demo(true), new LibraryReset($demo, self::AUDIO), new NullLogger())(new ResetDemoLibrary());
        static::assertSame(0, (int) $demo->fetchOne('SELECT COUNT(*) FROM article'));

        $own = self::library(3);
        new ResetDemoLibraryHandler(self::demo(false), new LibraryReset($own, self::AUDIO), new NullLogger())(new ResetDemoLibrary());
        static::assertSame(3, (int) $own->fetchOne('SELECT COUNT(*) FROM article'));
    }
}

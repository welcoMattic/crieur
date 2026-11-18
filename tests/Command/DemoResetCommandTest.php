<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\DemoResetCommand;
use App\Demo\LibraryReset;
use App\Demo\PublicDemo;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class DemoResetCommandTest extends TestCase
{
    private const string AUDIO = __DIR__.'/../../var/audio_test/demo-reset';

    protected function tearDown(): void
    {
        new Filesystem()->remove(self::AUDIO);
    }

    #[Test]
    public function itEmptiesTheLibraryAtTheResetTimeNotBefore(): void
    {
        $clock = new MockClock('2026-11-18 17:58:30 UTC');
        $connection = self::library(3);

        $tester = self::tester(self::demo(true, $clock), new LibraryReset($connection, self::AUDIO), $clock);

        static::assertSame(Command::SUCCESS, $tester->execute(['--at-next-reset' => true]));
        static::assertStringContainsString('Next reset at 2026-11-18 18:00 UTC.', $tester->getDisplay());
        static::assertStringContainsString('2026-11-18 18:00 UTC: the library is empty, 3 article(s) removed.', $tester->getDisplay());
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article'));
    }

    #[Test]
    public function itEmptiesNothingOutsideTheDemo(): void
    {
        $clock = new MockClock();
        $connection = self::library(2);

        static::assertSame(Command::FAILURE, self::tester(self::demo(false, $clock), new LibraryReset($connection, self::AUDIO), $clock)->execute([]));
        static::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM article'));
    }

    private static function library(int $articles): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE article (id INTEGER PRIMARY KEY)');
        $connection->executeStatement('CREATE TABLE segment (id INTEGER PRIMARY KEY, article_id INTEGER)');
        for ($id = 1; $id <= $articles; ++$id) {
            $connection->insert('article', ['id' => $id]);
            $connection->insert('segment', ['article_id' => $id]);
        }

        return $connection;
    }

    private static function tester(PublicDemo $demo, LibraryReset $reset, MockClock $clock): CommandTester
    {
        $application = new Application();
        $application->addCommand(new DemoResetCommand($demo, $reset, $clock));

        return new CommandTester($application->find('crieur:demo:reset'));
    }

    private static function demo(bool $enabled, MockClock $clock): PublicDemo
    {
        $storage = new InMemoryStorage();

        return new PublicDemo(
            $enabled,
            new RateLimiterFactory(['id' => 'demo_visitor', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '1 hour'], $storage),
            new RateLimiterFactory(['id' => 'demo_all', 'policy' => 'sliding_window', 'limit' => 30, 'interval' => '1 hour'], $storage),
            new RequestStack(),
            $clock,
        );
    }
}

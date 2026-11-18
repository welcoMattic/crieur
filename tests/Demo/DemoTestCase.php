<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Demo\PublicDemo;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * A public demo, enabled or not, and a small library in memory to empty.
 */
abstract class DemoTestCase extends TestCase
{
    protected const string AUDIO = __DIR__.'/../../var/audio_test/demo-reset';

    protected function tearDown(): void
    {
        new Filesystem()->remove(self::AUDIO);
    }

    protected static function demo(bool $enabled, ?MockClock $clock = null): PublicDemo
    {
        $storage = new InMemoryStorage();

        return new PublicDemo(
            $enabled,
            new RateLimiterFactory(['id' => 'demo_visitor', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '1 hour'], $storage),
            new RateLimiterFactory(['id' => 'demo_all', 'policy' => 'sliding_window', 'limit' => 30, 'interval' => '1 hour'], $storage),
            new RequestStack(),
            $clock ?? new MockClock(),
        );
    }

    protected static function library(int $articles): Connection
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
}

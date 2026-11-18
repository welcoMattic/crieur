<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\DemoResetCommand;
use App\Demo\LibraryReset;
use App\Tests\Demo\DemoTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DemoResetCommandTest extends DemoTestCase
{
    #[Test]
    public function itEmptiesTheLibraryOfTheDemoNow(): void
    {
        $connection = self::library(3);

        $tester = self::tester(true, new LibraryReset($connection, self::AUDIO));

        static::assertSame(Command::SUCCESS, $tester->execute([]));
        static::assertStringContainsString('The library is empty: 3 article(s) removed.', $tester->getDisplay());
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article'));
    }

    #[Test]
    public function itEmptiesNothingOutsideTheDemo(): void
    {
        $connection = self::library(2);

        static::assertSame(Command::FAILURE, self::tester(false, new LibraryReset($connection, self::AUDIO))->execute([]));
        static::assertSame(2, (int) $connection->fetchOne('SELECT COUNT(*) FROM article'));
    }

    private static function tester(bool $demo, LibraryReset $reset): CommandTester
    {
        $application = new Application();
        $application->addCommand(new DemoResetCommand(self::demo($demo), $reset));

        return new CommandTester($application->find('crieur:demo:reset'));
    }
}

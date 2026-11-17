<?php

declare(strict_types=1);

namespace App\Tests\Text;

use App\Text\Language;
use App\Text\ReadingTime;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReadingTimeTest extends TestCase
{
    #[Test]
    public function theTimeComesFromTheCharactersAndTheSpeed(): void
    {
        $text = str_repeat('é', 150);
        self::assertSame(10, ReadingTime::estimateSeconds($text, Language::French));
        self::assertSame(10, ReadingTime::estimateSeconds($text, Language::English));
        self::assertSame(20, ReadingTime::estimateSeconds($text, Language::French, 0.5));
        self::assertSame(5, ReadingTime::estimateSeconds($text, Language::French, 2.0));
        self::assertSame(7, ReadingTime::estimateSeconds($text, Language::English, 1.5));
        self::assertSame(0, ReadingTime::estimateSeconds('', Language::French));
    }

    #[Test]
    public function bothLanguagesShareTheRateOfTheSwiftEstimator(): void
    {
        self::assertSame(15.0, ReadingTime::charactersPerSecond(Language::French));
        self::assertSame(15.0, ReadingTime::charactersPerSecond(Language::English));
    }

    #[Test]
    public function theSpeedMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReadingTime::estimateSeconds('Bonjour', Language::French, 0.0);
    }
}

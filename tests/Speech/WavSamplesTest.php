<?php

declare(strict_types=1);

namespace App\Tests\Speech;

use App\Speech\Wav;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WavSamplesTest extends TestCase
{
    #[Test]
    public function samplesAreSigned16BitLittleEndian(): void
    {
        $wav = Wav::fromPcm("\x00\x00\xff\x7f\x00\x80\xff\xff\x01\x00", 16000);
        self::assertSame([0, 32767, -32768, -1, 1], $wav->samples());
        self::assertSame(5, $wav->sampleCount());
    }

    #[Test]
    public function fromSamplesIsTheInverseAndClampsOutOfRangeValues(): void
    {
        $samples = [0, 1, -1, 1234, -1234, 32767, -32768];
        $wav = Wav::fromSamples($samples, 22050);
        self::assertSame($samples, $wav->samples());
        self::assertSame(22050, $wav->sampleRate);
        self::assertSame([32767, -32768, 5], Wav::fromSamples([40000, -40000, 5])->samples());
        self::assertSame([], Wav::fromSamples([])->samples());
        self::assertSame('', Wav::fromSamples([])->pcm);
    }

    #[Test]
    public function aWavFileGivesBackItsSamples(): void
    {
        $samples = [100, -200, 300];
        self::assertSame($samples, Wav::fromBytes(Wav::fromSamples($samples)->toBytes())->samples());
    }
}

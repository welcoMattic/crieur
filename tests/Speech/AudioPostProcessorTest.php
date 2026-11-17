<?php

declare(strict_types=1);

namespace App\Tests\Speech;

use App\Speech\AudioPostProcessor;
use App\Speech\Wav;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ported from SpeechAudioTests.swift (R-TS-16), on synthetic 16-bit signals.
 */
final class AudioPostProcessorTest extends TestCase
{
    private const int RATE = 24000;

    private AudioPostProcessor $processor;

    protected function setUp(): void
    {
        $this->processor = new AudioPostProcessor();
    }

    #[Test]
    public function trimEdgesRemovesTheSilencesAroundTheSpeechAndKeepsThePauses(): void
    {
        $noise = 10 ** (-64 / 20);
        $audio = [
            ...self::silence(0.14, $noise), ...self::tone(1, 0.3), ...self::silence(0.3),
            ...self::tone(0.5, 0.3), ...self::silence(0.6, $noise),
        ];
        $trimmed = $this->processor->trimEdges($audio, self::RATE);
        $seconds = \count($trimmed) / self::RATE;
        // 40 ms before, the speech and its inner pause, 80 ms after (frames of 20 ms).
        self::assertEqualsWithDelta(0.04 + 1 + 0.3 + 0.5 + 0.08, $seconds, 0.025);
        // 5 ms fades: no click at the cuts.
        self::assertSame(0, $trimmed[0]);
        self::assertSame(0, $trimmed[\count($trimmed) - 1]);
    }

    #[Test]
    public function trimEdgesLeavesSilenceAndShortAudioAlone(): void
    {
        self::assertSame(self::silence(1), $this->processor->trimEdges(self::silence(1), self::RATE));
        $short = self::tone(0.03, 0.3); // under 3 frames
        self::assertSame($short, $this->processor->trimEdges($short, self::RATE));
        self::assertSame([], $this->processor->trimEdges([], self::RATE));
    }

    #[Test]
    public function normalizeBringsTheSpeechToTheTarget(): void
    {
        foreach ([0.05, 0.2, 0.5] as $amplitude) {
            $audio = [...self::silence(0.2), ...self::tone(1, $amplitude), ...self::silence(0.2)];
            $level = $this->processor->speechLevelDb($this->processor->normalizeLevel($audio, self::RATE, -24), self::RATE);
            self::assertNotNull($level);
            self::assertEqualsWithDelta(-24, $level, 0.05, "amplitude {$amplitude}");
        }
        self::assertNull($this->processor->speechLevelDb(self::silence(1), self::RATE));
        self::assertSame(self::silence(1), $this->processor->normalizeLevel(self::silence(1), self::RATE, -24));
    }

    #[Test]
    public function normalizeLimitsTheGainAndThePeak(): void
    {
        // Very soft speech: +15 dB at most.
        $soft = self::tone(1, 0.001);
        $before = $this->processor->speechLevelDb($soft, self::RATE);
        $after = $this->processor->speechLevelDb($this->processor->normalizeLevel($soft, self::RATE, -24), self::RATE);
        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertEqualsWithDelta(15, $after - $before, 0.05);

        // A loud click: the peak stays at -1 dBFS or less.
        $tone = self::tone(1, 0.01);
        $click = [...\array_slice($tone, 0, 1000), (int) round(0.9 * 32767), ...\array_slice($tone, 1001)];
        $normalized = $this->processor->normalizeLevel($click, self::RATE, -24);
        $peak = max(0, ...array_map(abs(...), $normalized)) / 32767;
        self::assertLessThanOrEqual(-1 + 1e-3, 20 * log10($peak));

        // Very loud speech: -20 dB at most.
        $loud = self::tone(1, 1);
        $loudLevel = $this->processor->speechLevelDb($loud, self::RATE);
        $lowered = $this->processor->speechLevelDb($this->processor->normalizeLevel($loud, self::RATE, -60), self::RATE);
        self::assertNotNull($loudLevel);
        self::assertNotNull($lowered);
        self::assertEqualsWithDelta(-20, $lowered - $loudLevel, 0.05);
    }

    #[Test]
    public function processTrimsThenSetsTheLevelOfAWav(): void
    {
        $noise = 10 ** (-64 / 20);
        $audio = [...self::silence(0.14, $noise), ...self::tone(1, 0.3), ...self::silence(0.6, $noise)];
        $wav = $this->processor->process(Wav::fromSamples($audio, self::RATE));

        self::assertSame(self::RATE, $wav->sampleRate);
        self::assertEqualsWithDelta(1120, $wav->durationMs(), 25);
        self::assertEqualsWithDelta(-24, $this->processor->speechLevelDb($wav->samples(), self::RATE) ?? 0.0, 0.05);
        self::assertSame('', $this->processor->process(Wav::fromPcm(''))->pcm);
    }

    #[Test]
    public function theSettingsComeFromTheConstructor(): void
    {
        $audio = [...self::silence(0.5), ...self::tone(1, 0.05), ...self::silence(0.5)];
        $wav = Wav::fromSamples($audio, self::RATE);

        $kept = (new AudioPostProcessor(leadSeconds: 0.2, tailSeconds: 0.3, levelTargetDb: null))->process($wav);
        self::assertEqualsWithDelta(1500, $kept->durationMs(), 25);
        $processor = new AudioPostProcessor();
        self::assertEqualsWithDelta($processor->speechLevelDb($audio, self::RATE) ?? 0.0, $processor->speechLevelDb($kept->samples(), self::RATE) ?? 0.0, 0.05);

        $quieter = (new AudioPostProcessor(levelTargetDb: -30))->process($wav);
        self::assertEqualsWithDelta(-30, $processor->speechLevelDb($quieter->samples(), self::RATE) ?? 0.0, 0.05);
    }

    #[Test]
    public function theFramesFollowTheSampleRate(): void
    {
        $rate = 16000;
        $audio = [...self::silence(0.5, 0.0, $rate), ...self::tone(1, 0.3, $rate), ...self::silence(0.5, 0.0, $rate)];
        $trimmed = $this->processor->trimEdges($audio, $rate);
        self::assertEqualsWithDelta(1.12, \count($trimmed) / $rate, 0.025);
    }

    /**
     * @return list<int>
     */
    private static function tone(float $seconds, float $amplitude, int $rate = self::RATE, float $frequency = 220.0): array
    {
        $samples = [];
        for ($i = 0, $count = (int) ($seconds * $rate); $i < $count; ++$i) {
            $samples[] = (int) round($amplitude * sin(2 * M_PI * $frequency * $i / $rate) * 32767);
        }

        return $samples;
    }

    /**
     * @return list<int>
     */
    private static function silence(float $seconds, float $level = 0.0, int $rate = self::RATE): array
    {
        return array_fill(0, (int) ($seconds * $rate), (int) round($level * 32767));
    }
}

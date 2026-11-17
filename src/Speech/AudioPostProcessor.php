<?php

declare(strict_types=1);

namespace App\Speech;

/**
 * Evens out the chunks: trims the silence the engines add around each sentence (Crieur adds its
 * own pauses) and brings every chunk to the same speech level (R-TS-16).
 *
 * Port of SpeechAudio.swift (trimEdges, normalizeLevel) on signed 16-bit samples: full scale is
 * 32767, as the Swift app wrote its PCM.
 */
final readonly class AudioPostProcessor
{
    /**
     * Analysis frame: 20 ms.
     */
    public const float FRAME_SECONDS = 0.02;

    /**
     * Fades at the cuts: 5 ms, no click.
     */
    public const float FADE_SECONDS = 0.005;

    private const float FULL_SCALE = 32767.0;

    /**
     * The 1e-12 of the Swift code (squared float samples), in squared 16-bit units.
     */
    private const float EPSILON = 1e-12 * self::FULL_SCALE * self::FULL_SCALE;

    /**
     * @param float      $edgeThresholdDb frames this far under the loudest frame are silence
     * @param float      $leadSeconds     silence kept before the speech
     * @param float      $tailSeconds     silence kept after the speech
     * @param float|null $levelTargetDb   speech level of each chunk in dBFS; null keeps the level of the engine
     */
    public function __construct(
        private float $edgeThresholdDb = -50.0,
        private float $leadSeconds = 0.04,
        private float $tailSeconds = 0.08,
        private ?float $levelTargetDb = -24.0,
        private float $maxGainDb = 15.0,
        private float $minGainDb = -20.0,
    ) {
    }

    public function process(Wav $wav): Wav
    {
        $samples = $this->trimEdges($wav->samples(), $wav->sampleRate);
        if (null !== $this->levelTargetDb) {
            $samples = $this->normalizeLevel($samples, $wav->sampleRate, $this->levelTargetDb);
        }

        return Wav::fromSamples($samples, $wav->sampleRate);
    }

    /**
     * Removes the silence before and after the speech and keeps the pauses inside it.
     *
     * Sound: two frames in a row less than the threshold under the loudest frame. -50 dB keeps the
     * soft end of a word; Phonon adds 0.14 s before and 0.5 to 0.7 s after each sentence, at about
     * -64 dB. The audio is unchanged when it has fewer than 3 frames or no sound.
     *
     * @param list<int> $samples
     *
     * @return list<int>
     */
    public function trimEdges(array $samples, int $sampleRate): array
    {
        [$frame, $rms] = self::frameRms($samples, $sampleRate);
        $frames = \count($rms);
        if ($frames < 3) {
            return $samples;
        }

        $floor = max($rms) * 10 ** ($this->edgeThresholdDb / 20);
        $firstPair = $lastPair = null;
        for ($i = 0; $i < $frames - 1; ++$i) {
            if ($rms[$i] > $floor && $rms[$i + 1] > $floor) {
                $firstPair ??= $i;
                $lastPair = $i;
            }
        }
        if (null === $firstPair || null === $lastPair) {
            return $samples;
        }

        $count = \count($samples);
        $first = max($firstPair - (int) round($this->leadSeconds / self::FRAME_SECONDS), 0);
        $last = $lastPair + 2 + (int) round($this->tailSeconds / self::FRAME_SECONDS);
        $end = $last >= $frames ? $count : $last * $frame;
        $piece = \array_slice($samples, $first * $frame, $end - $first * $frame);

        $fade = max(1, (int) (self::FADE_SECONDS * $sampleRate));
        $length = \count($piece);
        if ($length > 2 * $fade) {
            // numpy.linspace(0, 1, fade): both ends included.
            $step = 1 / max($fade - 1, 1);
            for ($i = 0; $i < $fade; ++$i) {
                if ($first > 0) {
                    $piece[$i] = self::round($piece[$i] * $i * $step);
                }
                if ($end < $count) {
                    $piece[$length - $fade + $i] = self::round($piece[$length - $fade + $i] * ($fade - 1 - $i) * $step);
                }
            }
        }

        return array_values($piece);
    }

    /**
     * RMS level of the speech frames in dBFS, or null for silence. Speech: the frames above a
     * tenth of the loudest one (and above -80 dBFS).
     *
     * @param list<int> $samples
     */
    public function speechLevelDb(array $samples, int $sampleRate): ?float
    {
        [, $rms] = self::frameRms($samples, $sampleRate);
        if ([] === $rms) {
            return null;
        }

        $floor = max(max($rms) * 0.1, 1e-4 * self::FULL_SCALE);
        $sum = 0.0;
        $voiced = 0;
        foreach ($rms as $value) {
            if ($value > $floor) {
                $sum += $value * $value;
                ++$voiced;
            }
        }

        return 0 === $voiced ? null : 20 * log10(sqrt($sum / $voiced) / self::FULL_SCALE);
    }

    /**
     * Brings the speech level to $targetDb dBFS: the volume stays the same from one chunk or voice
     * to the next. The gain stays in [minGainDb, maxGainDb] and keeps the peak at -1 dBFS or lower.
     *
     * @param list<int> $samples
     *
     * @return list<int>
     */
    public function normalizeLevel(array $samples, int $sampleRate, float $targetDb): array
    {
        $level = $this->speechLevelDb($samples, $sampleRate);
        if (null === $level || [] === $samples) {
            return $samples;
        }

        $peak = max(max($samples), -min($samples)) / self::FULL_SCALE;
        $headroom = -1.0 - 20 * log10(max($peak, 1e-6));
        $gainDb = max($this->minGainDb, min($targetDb - $level, $this->maxGainDb, $headroom));
        $gain = 10 ** ($gainDb / 20);

        $limit = (int) self::FULL_SCALE;
        foreach ($samples as $i => $sample) {
            $value = $sample * $gain;
            $value = $value >= 0 ? (int) ($value + 0.5) : (int) ($value - 0.5);
            $samples[$i] = $value > $limit ? $limit : ($value < -$limit ? -$limit : $value);
        }

        return $samples;
    }

    /**
     * The RMS of each whole frame of 20 ms, in 16-bit units.
     *
     * @param list<int> $samples
     *
     * @return array{int, list<float>} the frame length in samples, and the RMS of each frame
     */
    private static function frameRms(array $samples, int $sampleRate): array
    {
        $frame = max(1, (int) (self::FRAME_SECONDS * $sampleRate));
        $frames = intdiv(\count($samples), $frame);
        $rms = [];
        for ($index = 0, $offset = 0; $index < $frames; ++$index) {
            $sum = 0;
            for ($end = $offset + $frame; $offset < $end; ++$offset) {
                $sum += $samples[$offset] * $samples[$offset];
            }
            $rms[] = sqrt($sum / $frame + self::EPSILON);
        }

        return [$frame, $rms];
    }

    private static function round(float $value): int
    {
        return $value >= 0 ? (int) ($value + 0.5) : (int) ($value - 0.5);
    }
}

<?php

declare(strict_types=1);

namespace App\Speech;

/**
 * Mono 16-bit PCM audio in a RIFF/WAVE container, the format every voice engine of Crieur answers.
 */
final readonly class Wav
{
    private function __construct(
        public int $sampleRate,
        public string $pcm,
    ) {
    }

    public static function fromPcm(string $pcm, int $sampleRate = 24000): self
    {
        if (0 !== \strlen($pcm) % 2) {
            throw new \InvalidArgumentException('16-bit PCM must have an even number of bytes.');
        }

        return new self($sampleRate, $pcm);
    }

    public static function silence(int $milliseconds, int $sampleRate = 24000): self
    {
        return new self($sampleRate, str_repeat("\0\0", intdiv(max(0, $milliseconds) * $sampleRate, 1000)));
    }

    /**
     * Reads a WAV file: mono or stereo (mixed down), 16-bit PCM, any sample rate.
     */
    public static function fromBytes(string $bytes): self
    {
        if (\strlen($bytes) < 12 || !str_starts_with($bytes, 'RIFF') || 'WAVE' !== substr($bytes, 8, 4)) {
            throw new \InvalidArgumentException('Not a RIFF/WAVE file.');
        }

        $offset = 12;
        $channels = $sampleRate = $bitsPerSample = null;
        $data = null;
        $length = \strlen($bytes);
        while ($offset + 8 <= $length) {
            $chunkId = substr($bytes, $offset, 4);
            $size = unpack('V', substr($bytes, $offset + 4, 4))[1] ?? 0;
            \assert(\is_int($size));
            $body = $offset + 8;
            if ('fmt ' === $chunkId) {
                $format = unpack('vformat/vchannels/VsampleRate/VbyteRate/vblockAlign/vbitsPerSample', substr($bytes, $body, 16));
                if (false === $format || 1 !== $format['format']) {
                    throw new \InvalidArgumentException('Only PCM WAV files are supported.');
                }
                $channels = $format['channels'];
                $sampleRate = $format['sampleRate'];
                $bitsPerSample = $format['bitsPerSample'];
            } elseif ('data' === $chunkId) {
                // Streaming encoders write 0 or 0xFFFFFFFF when they do not know the size yet.
                $data = substr($bytes, $body, 0 === $size || $size > $length - $body ? $length - $body : $size);
                break;
            }
            $offset = $body + $size + ($size % 2);
        }

        if (null === $data || null === $channels || null === $sampleRate || 16 !== $bitsPerSample) {
            throw new \InvalidArgumentException('The WAV file must hold 16-bit PCM data.');
        }

        if (1 === $channels) {
            return self::fromPcm(substr($data, 0, \strlen($data) - \strlen($data) % 2), $sampleRate);
        }

        $samples = array_values(unpack('s*', substr($data, 0, \strlen($data) - \strlen($data) % (2 * $channels))) ?: []);
        $mono = [];
        for ($i = 0, $count = \count($samples); $i < $count; $i += $channels) {
            $mono[] = intdiv(array_sum(\array_slice($samples, $i, $channels)), $channels);
        }

        return self::fromPcm(pack('v*', ...$mono), $sampleRate);
    }

    /**
     * Signed 16-bit samples, clamped to [-32768, 32767].
     *
     * @param list<int> $samples
     */
    public static function fromSamples(array $samples, int $sampleRate = 24000): self
    {
        if ([] !== $samples && (max($samples) > 32767 || min($samples) < -32768)) {
            $samples = array_map(static fn (int $sample): int => max(-32768, min(32767, $sample)), $samples);
        }

        return new self($sampleRate, [] === $samples ? '' : pack('v*', ...$samples));
    }

    /**
     * The signed 16-bit samples.
     *
     * @return list<int>
     */
    public function samples(): array
    {
        if ('' === $this->pcm) {
            return [];
        }

        // "s" reads the machine byte order: little-endian on every platform PHP runs Crieur on.
        if ("\x01\x00" === pack('S', 1)) {
            $values = unpack('s*', $this->pcm);
            /** @var list<int> $samples */
            $samples = false === $values ? [] : array_values($values);

            return $samples;
        }

        $values = unpack('v*', $this->pcm);

        return false === $values ? [] : array_values(array_map(static fn (mixed $value): int => ((int) $value + 32768) % 65536 - 32768, $values));
    }

    public function sampleCount(): int
    {
        return intdiv(\strlen($this->pcm), 2);
    }

    public function durationMs(): int
    {
        return intdiv(\strlen($this->pcm) * 500, $this->sampleRate);
    }

    public function append(self $other): self
    {
        if ($other->sampleRate !== $this->sampleRate) {
            throw new \InvalidArgumentException(\sprintf('Cannot join %d Hz and %d Hz audio.', $this->sampleRate, $other->sampleRate));
        }

        return new self($this->sampleRate, $this->pcm.$other->pcm);
    }

    public function toBytes(): string
    {
        $size = \strlen($this->pcm);

        return 'RIFF'.pack('V', 36 + $size).'WAVE'
            .'fmt '.pack('VvvVVvv', 16, 1, 1, $this->sampleRate, $this->sampleRate * 2, 2, 16)
            .'data'.pack('V', $size).$this->pcm;
    }
}

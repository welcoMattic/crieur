<?php

declare(strict_types=1);

namespace App\Speech;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Stores synthesized audio on disk, addressed by a hash of the voice and the spoken text: the same
 * sentence read by the same voice is synthesized once, whatever the article.
 */
final readonly class AudioStore
{
    private Filesystem $filesystem;

    public function __construct(
        #[Autowire(param: 'crieur.audio_dir')]
        private string $directory,
    ) {
        $this->filesystem = new Filesystem();
    }

    public static function key(Voice $voice, string $spoken): string
    {
        return hash('xxh128', implode("\0", [$voice->engine, $voice->model, $voice->id, $spoken]));
    }

    public function has(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function path(string $key): string
    {
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $key)) {
            throw new \InvalidArgumentException('Invalid audio key.');
        }

        return \sprintf('%s/%s/%s.wav', $this->directory, substr($key, 0, 2), $key);
    }

    public function read(string $key): string
    {
        $content = file_get_contents($this->path($key));
        if (false === $content) {
            throw new \RuntimeException(\sprintf('The audio "%s" is missing.', $key));
        }

        return $content;
    }

    public function write(string $key, string $wav): void
    {
        $this->filesystem->dumpFile($this->path($key), $wav);
    }
}

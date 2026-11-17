<?php

declare(strict_types=1);

namespace App\Library;

use App\Entity\Article;
use App\Speech\AudioStore;
use App\Speech\Wav;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Joins the chunks of a ready article, with their pauses, into one episode for podcast apps.
 *
 * The episode is AAC in an M4A file when ffmpeg is installed (about 1 MB per minute), else WAV.
 */
final readonly class EpisodeBuilder
{
    private Filesystem $filesystem;

    public function __construct(
        private AudioStore $store,
        #[Autowire(param: 'crieur.audio_dir')]
        private string $audioDirectory,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * @return array{path: string, mimeType: string, durationMs: int}
     */
    public function build(Article $article): array
    {
        // Keyed on the audio of the chunks: a voice change or a rewrite gives another episode.
        $audio = hash('xxh128', implode(',', array_map(static fn ($segment): string => (string) $segment->getAudioKey(), $article->getSegments()->toArray())));
        $base = \sprintf('%s/episodes/%s-%s', $this->audioDirectory, $article->getId()->toRfc4122(), $audio);
        $wavPath = $base.'.wav';
        $m4aPath = $base.'.m4a';

        if (!is_file($wavPath)) {
            $episode = Wav::silence(0);
            foreach ($article->getSegments() as $segment) {
                $key = $segment->getAudioKey();
                if (null === $key) {
                    throw new \LogicException('The episode of an article is built once every chunk has its audio.');
                }
                $episode = $episode->append(Wav::fromBytes($this->store->read($key)))->append(Wav::silence($segment->getPauseAfterMs(), $episode->sampleRate));
            }
            $this->filesystem->dumpFile($wavPath, $episode->toBytes());
        }

        $durationMs = Wav::fromBytes((string) file_get_contents($wavPath))->durationMs();

        if (!is_file($m4aPath) && null !== $ffmpeg = (new ExecutableFinder())->find('ffmpeg')) {
            $process = new Process([$ffmpeg, '-nostdin', '-loglevel', 'error', '-y', '-i', $wavPath, '-c:a', 'aac', '-b:a', '96k', '-movflags', '+faststart', $m4aPath.'.part.m4a']);
            $process->setTimeout(300);
            if (0 === $process->run()) {
                $this->filesystem->rename($m4aPath.'.part.m4a', $m4aPath, true);
            }
        }

        return is_file($m4aPath)
            ? ['path' => $m4aPath, 'mimeType' => 'audio/mp4', 'durationMs' => $durationMs]
            : ['path' => $wavPath, 'mimeType' => 'audio/wav', 'durationMs' => $durationMs];
    }
}

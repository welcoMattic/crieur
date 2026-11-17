<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Editor\SpeechEditor;
use App\Entity\Article;
use App\Entity\Segment;
use App\Message\SynthesizeArticle;
use App\Repository\ArticleRepository;
use App\Speech\SpeechSynthesizer;
use App\Speech\Voice;
use App\Speech\VoiceCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Synthesizes the chunks of an article in reading order, one at a time, so that the listener can
 * start as soon as the title is ready. Meanwhile the editor worker (EditArticleHandler) rewrites
 * the next chunks for the ear, ahead of the voice.
 *
 * The run stops as soon as the article gets a new revision (another voice or language): the
 * message sent with that change synthesizes the rest.
 */
#[AsMessageHandler]
final readonly class SynthesizeArticleHandler
{
    private const int ATTEMPTS = 2;

    /**
     * A run works this long, then hands over to the next message of the queue and sends itself
     * again: a new article never waits for the whole synthesis of another one.
     */
    private const float SLICE_SECONDS = 15.0;

    /**
     * How long the voice waits for the editor worker before reading the text of the rules.
     */
    private const float EDITOR_WAIT_SECONDS = 2.5;

    public function __construct(
        private ArticleRepository $articles,
        private EntityManagerInterface $entityManager,
        private VoiceCatalog $voices,
        private SpeechSynthesizer $synthesizer,
        private SpeechEditor $editor,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SynthesizeArticle $message): void
    {
        $article = $this->articles->get($message->articleId);
        $language = $article?->getLanguage();
        if (null === $article || null === $language || !$this->isCurrent($article, $message->revision) || !$article->getStatus()->isPlayable()) {
            return;
        }

        $engine = $this->voices->engine($article->getVoiceEngine());
        $voice = $this->voices->voice($engine->name, $language, $article->getVoiceGender());
        $editing = null !== $this->editor->backend();
        $segments = array_values($article->getSegments()->toArray());
        $from = min(max(0, $message->fromPosition), max(0, \count($segments) - 1));
        $order = [...\array_slice($segments, $from), ...\array_slice($segments, 0, $from)];
        $startedAt = hrtime(true);
        $rewritten = false;

        foreach ($order as $segment) {
            if ($segment->isReady()) {
                continue;
            }

            if ((hrtime(true) - $startedAt) / 1e9 > self::SLICE_SECONDS) {
                $this->bus->dispatch(new SynthesizeArticle($article->getId(), $message->revision, $message->fromPosition));

                return;
            }

            if ($editing && !$segment->isEdited() && !EditArticleHandler::isOpening($segment, $segments)) {
                $this->waitForEditor($segment);
            }

            if ('' === trim($segment->getSpoken())) {
                $segment->attachAudio(...$this->silence($voice));
                $this->entityManager->flush();
                continue;
            }

            if (!$this->isCurrent($article, $message->revision)) {
                return;
            }

            $spoken = $segment->getSpoken();
            try {
                $audio = $this->synthesize($spoken, $voice);
            } catch (PlatformException|TransportExceptionInterface|\RuntimeException $e) {
                $this->logger->error('Synthesis of chunk {position} of {article} failed: {message}', ['position' => $segment->getPosition(), 'article' => $article->getId()->toRfc4122(), 'message' => $e->getMessage(), 'exception' => $e]);
                $article->fail($e instanceof TransportExceptionInterface || $e->getPrevious() instanceof TransportExceptionInterface ? 'synthesis.engine_unavailable' : 'synthesis.failed', $this->clock->now());
                $this->entityManager->flush();

                return;
            }

            // The editor may have rewritten the chunk meanwhile: its new text gets its own audio.
            $this->entityManager->refresh($segment);
            if ($segment->getSpoken() !== $spoken) {
                $rewritten = true;
                continue;
            }

            $segment->attachAudio($audio['key'], $audio['durationMs']);
            $this->entityManager->flush();
        }

        if ($rewritten) {
            $this->bus->dispatch(new SynthesizeArticle($article->getId(), $message->revision, $message->fromPosition));

            return;
        }

        $this->entityManager->refresh($article);
        if ($article->getRevision() === $message->revision && $article->countReadySegments() === $article->getSegments()->count()) {
            $article->markReady($this->clock->now());
            $this->entityManager->flush();
        }
    }




    /**
     * Gives the editor worker a few seconds to rewrite the chunk the voice is about to read, then
     * goes on with the text of the rules: the listener never waits for long.
     */
    private function waitForEditor(Segment $segment): void
    {
        $deadline = hrtime(true) + (int) (self::EDITOR_WAIT_SECONDS * 1e9);
        while (hrtime(true) < $deadline) {
            usleep(250_000);
            $this->entityManager->refresh($segment);
            if ($segment->isEdited()) {
                return;
            }
        }
    }

    /**
     * @return array{key: string, durationMs: int}
     */
    private function synthesize(string $spoken, Voice $voice): array
    {
        for ($attempt = 1; ; ++$attempt) {
            try {
                return $this->synthesizer->synthesize($spoken, $voice);
            } catch (PlatformException|TransportExceptionInterface|\RuntimeException $e) {
                if ($attempt >= self::ATTEMPTS) {
                    throw $e;
                }
                usleep(500_000);
            }
        }
    }

    /**
     * A chunk the editor emptied still takes its place in the timeline, as a short silence.
     *
     * @return array{string, int}
     */
    private function silence(Voice $voice): array
    {
        $audio = $this->synthesizer->silence(1, $voice);

        return [$audio['key'], $audio['durationMs']];
    }

    /**
     * Reads the revision from the database: the web request that changes the voice runs in
     * another process, so the entity of this worker does not know about it.
     *
     * @phpstan-impure
     */
    private function isCurrent(Article $article, int $revision): bool
    {
        $current = $this->entityManager->getConnection()->fetchOne('SELECT revision FROM article WHERE id = ?', [$article->getId()->toBinary()]);

        return false !== $current && (int) $current === $revision;
    }
}

<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Editor\EditorUnavailable;
use App\Editor\SpeechEditor;
use App\Entity\Article;
use App\Entity\Segment;
use App\Message\SynthesizeArticle;
use App\Repository\ArticleRepository;
use App\Speech\SpeechSynthesizer;
use App\Speech\Voice;
use App\Speech\VoiceCatalog;
use App\Text\BlockKind;
use App\Text\Language;
use App\Text\TextNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Synthesizes the chunks of an article in reading order, one at a time, so that the listener can
 * start as soon as the title is ready. Ahead of the voice, the speech editor rewrites the next
 * batch of chunks for the ear.
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

    public function __construct(
        private ArticleRepository $articles,
        private EntityManagerInterface $entityManager,
        private VoiceCatalog $voices,
        private SpeechSynthesizer $synthesizer,
        private SpeechEditor $editor,
        private TextNormalizer $normalizer,
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
        $startedAt = hrtime(true);

        foreach ($segments as $index => $segment) {
            if ($segment->isReady()) {
                continue;
            }

            if ((hrtime(true) - $startedAt) / 1e9 > self::SLICE_SECONDS) {
                $this->bus->dispatch(new SynthesizeArticle($article->getId(), $message->revision));

                return;
            }

            if ($editing && !$segment->isEdited() && BlockKind::Title !== $segment->getKind()) {
                $editing = $this->editAhead($article, $language, \array_slice($segments, $index), $engine->spellsIntegers());
            }

            if ('' === trim($segment->getSpoken())) {
                $segment->attachAudio(...$this->silence($voice));
                $this->entityManager->flush();
                continue;
            }

            if (!$this->isCurrent($article, $message->revision)) {
                return;
            }

            try {
                $audio = $this->synthesize($segment->getSpoken(), $voice);
            } catch (PlatformException|TransportExceptionInterface|\RuntimeException $e) {
                $this->logger->error('Synthesis of chunk {position} of {article} failed: {message}', ['position' => $segment->getPosition(), 'article' => $article->getId()->toRfc4122(), 'message' => $e->getMessage(), 'exception' => $e]);
                $article->fail($e instanceof TransportExceptionInterface || $e->getPrevious() instanceof TransportExceptionInterface ? 'synthesis.engine_unavailable' : 'synthesis.failed', $this->clock->now());
                $this->entityManager->flush();

                return;
            }

            $segment->attachAudio($audio['key'], $audio['durationMs']);
            $this->entityManager->flush();
        }

        $this->entityManager->refresh($article);
        if ($article->getRevision() === $message->revision && $article->countReadySegments() === $article->getSegments()->count()) {
            $article->markReady($this->clock->now());
            $this->entityManager->flush();
        }
    }

    /**
     * @param list<Segment> $upcoming the segments from the current one to the end
     *
     * @return bool whether the editor should be asked again for the next batches
     */
    private function editAhead(Article $article, Language $language, array $upcoming, bool $spellIntegers): bool
    {
        $budget = SpeechEditor::batchCharacters($this->editedCharacters($article));
        $batch = [];
        $characters = 0;
        foreach ($upcoming as $segment) {
            if ($characters >= $budget || \count($batch) >= SpeechEditor::BATCH_CHUNKS) {
                break;
            }
            if (!$segment->isEdited() && BlockKind::Title !== $segment->getKind()) {
                $batch[$segment->getPosition()] = $segment;
                $characters += mb_strlen($segment->getText());
            }
        }

        $model = $this->editor->model() ?? 'rules';
        // The summary makes the answer longer: not in the first batch, which the listener waits for.
        $isLastBatch = \count($batch) === \count(array_filter($upcoming, static fn (Segment $segment): bool => !$segment->isEdited() && BlockKind::Title !== $segment->getKind()));
        $withSummary = null === $article->getSummary() && ($budget > SpeechEditor::FIRST_BATCH_CHARACTERS || $isLastBatch);

        try {
            $edited = $this->editor->edit(
                $language,
                $article->getDisplayTitle(),
                array_map(static fn (Segment $segment): string => $segment->getText(), $batch),
                $withSummary,
            );
        } catch (EditorUnavailable) {
            foreach ($batch as $segment) {
                $segment->rewrite($segment->getSpoken());
            }
            $article->edit('rules', null, $this->clock->now());
            $this->entityManager->flush();

            return false;
        }

        foreach ($batch as $position => $segment) {
            $spoken = $edited->spoken[$position] ?? null;
            $segment->rewrite(null === $spoken ? $segment->getSpoken() : $this->normalizer->normalize($spoken, $language, $spellIntegers));
        }

        $article->edit($model, $edited->summary, $this->clock->now());
        $this->entityManager->flush();

        return true;
    }

    private function editedCharacters(Article $article): int
    {
        $characters = 0;
        foreach ($article->getSegments() as $segment) {
            if ($segment->isEdited()) {
                $characters += mb_strlen($segment->getText());
            }
        }

        return $characters;
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

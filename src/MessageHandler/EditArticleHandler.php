<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Editor\EditorUnavailable;
use App\Editor\SpeechEditor;
use App\Entity\Article;
use App\Entity\Segment;
use App\Message\EditArticle;
use App\Message\SynthesizeArticle;
use App\Repository\ArticleRepository;
use App\Speech\VoiceCatalog;
use App\Text\BlockKind;
use App\Text\TextNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The speech editor at work, batch after batch, ahead of the voice: small batches first so that
 * the listener never waits, then larger ones (SpeechEditor::batchCharacters()).
 *
 * The title and the first chunk of the body keep the text of the rules: the voice reads them at
 * once. When the model cannot be reached, the rest of the article keeps the rules too.
 */
#[AsMessageHandler]
final readonly class EditArticleHandler
{
    /**
     * A run edits this long, then sends itself again: other articles get their turn.
     */
    private const float SLICE_SECONDS = 30.0;

    public function __construct(
        private ArticleRepository $articles,
        private EntityManagerInterface $entityManager,
        private SpeechEditor $editor,
        private VoiceCatalog $voices,
        private TextNormalizer $normalizer,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(EditArticle $message): void
    {
        $article = $this->articles->get($message->articleId);
        $language = $article?->getLanguage();
        if (null === $article || null === $language || $article->getRevision() !== $message->revision || !$article->getStatus()->isPlayable()) {
            return;
        }

        $spellIntegers = $this->voices->engine($article->getVoiceEngine())->spellsIntegers();
        $segments = array_values($article->getSegments()->toArray());
        $startedAt = hrtime(true);
        $lostAudio = null;

        while ([] !== $pending = $this->pending($segments)) {
            if ((hrtime(true) - $startedAt) / 1e9 > self::SLICE_SECONDS) {
                $this->bus->dispatch(new EditArticle($article->getId(), $message->revision));
                break;
            }

            if (!$this->isCurrent($article, $message->revision)) {
                return;
            }

            $budget = SpeechEditor::batchCharacters($this->editedCharacters($segments));
            $batch = [];
            $characters = 0;
            foreach ($pending as $segment) {
                if ($characters >= $budget || \count($batch) >= SpeechEditor::BATCH_CHUNKS) {
                    break;
                }
                $batch[$segment->getPosition()] = $segment;
                $characters += mb_strlen($segment->getText());
            }

            // The summary makes the answer longer: not in the first batches, which the listener waits for.
            $withSummary = null === $article->getSummary() && ($budget > SpeechEditor::FIRST_BATCH_CHARACTERS || \count($batch) === \count($pending));

            try {
                $edited = $this->editor->edit($language, $article->getDisplayTitle(), array_map(static fn (Segment $segment): string => $segment->getText(), $batch), $withSummary);
            } catch (EditorUnavailable) {
                // The rules read the rest: nothing waits for an editor that does not answer.
                foreach ($pending as $segment) {
                    $segment->rewrite($segment->getSpoken());
                }
                $article->edit('rules', null, $this->clock->now());
                $this->entityManager->flush();

                // Chunks an earlier batch rewrote after the voice read them are still read again.
                break;
            }

            if (!$this->isCurrent($article, $message->revision)) {
                return;
            }

            foreach ($batch as $position => $segment) {
                $this->entityManager->refresh($segment);
                $hadAudio = $segment->isReady();
                $spoken = $edited->spoken[$position] ?? null;
                $segment->rewrite(null === $spoken ? $segment->getSpoken() : $this->normalizer->normalize($spoken, $language, $spellIntegers));
                if ($hadAudio && !$segment->isReady()) {
                    $lostAudio = min($lostAudio ?? $position, $position);
                }
            }

            $article->edit($this->editor->model() ?? 'rules', $edited->summary, $this->clock->now());
            $this->entityManager->flush();
            if (null !== $lostAudio) {
                // The synthesis worker may have marked the article ready meanwhile: it is not anymore.
                $this->entityManager->refresh($article);
                $article->resumeSynthesis($this->clock->now());
                $this->entityManager->flush();
            }
        }

        // A chunk the voice read before the editor reached it is read again, with the new text.
        if (null !== $lostAudio) {
            $this->bus->dispatch(new SynthesizeArticle($article->getId(), $message->revision, $lostAudio));
        }
    }

    /**
     * @param list<Segment> $segments
     *
     * @return list<Segment> the segments still to edit, in reading order
     */
    private function pending(array $segments): array
    {
        return array_values(array_filter($segments, static fn (Segment $segment): bool => !$segment->isEdited() && !self::isOpening($segment, $segments)));
    }

    /**
     * The title and the first chunk of the body are read as the rules prepared them.
     *
     * @param list<Segment> $segments
     */
    public static function isOpening(Segment $segment, array $segments): bool
    {
        if (BlockKind::Title === $segment->getKind()) {
            return true;
        }

        foreach ($segments as $candidate) {
            if (BlockKind::Title !== $candidate->getKind()) {
                return $candidate === $segment;
            }
        }

        return false;
    }

    /**
     * @param list<Segment> $segments
     */
    private function editedCharacters(array $segments): int
    {
        $characters = 0;
        foreach ($segments as $segment) {
            if ($segment->isEdited()) {
                $characters += mb_strlen($segment->getText());
            }
        }

        return $characters;
    }

    /**
     * @phpstan-impure
     */
    private function isCurrent(Article $article, int $revision): bool
    {
        $current = $this->entityManager->getConnection()->fetchOne('SELECT revision FROM article WHERE id = ?', [$article->getId()->toBinary()]);

        return false !== $current && (int) $current === $revision;
    }
}

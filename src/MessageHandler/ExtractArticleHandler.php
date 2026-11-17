<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Extraction\ArticleExtractor;
use App\Extraction\ExtractionFailed;
use App\Message\ExtractArticle;
use App\Message\SynthesizeArticle;
use App\Repository\ArticleRepository;
use App\Speech\VoiceCatalog;
use App\Text\ArticlePreparer;
use App\Text\Block;
use App\Text\BlockKind;
use App\Text\HtmlBlockExtractor;
use App\Text\LanguageDetector;
use App\Text\NoReadableText;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Fetches the page, extracts the article, resolves its language and prepares its chunks with the
 * deterministic rules, then hands it to the synthesis queue. Within a second or two of the click,
 * the article has a title and chunks that can be synthesized.
 */
#[AsMessageHandler]
final readonly class ExtractArticleHandler
{
    public function __construct(
        private ArticleRepository $articles,
        private ArticleExtractor $extractor,
        private HtmlBlockExtractor $blocks,
        private LanguageDetector $languages,
        private ArticlePreparer $preparer,
        private VoiceCatalog $voices,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ExtractArticle $message): void
    {
        $article = $this->articles->get($message->articleId);
        if (null === $article) {
            return;
        }

        $article->startExtraction($this->clock->now());
        $this->articles->save($article);

        try {
            $extracted = $this->extractor->extract($article->getUrl());
            $blocks = self::withoutByline($this->blocks->extract($extracted->html), $extracted->author);
            $sample = $extracted->title."\n".implode("\n", array_map(static fn ($block): string => $block->text, $blocks));

            $language = $article->getLanguageSetting()->forced()
                ?? $this->languages->detect($sample, $extracted->languageHint)
                ?? throw new ExtractionFailed(ExtractionFailed::UNSUPPORTED_LANGUAGE, (string) $extracted->languageHint);

            $article->describe(
                $extracted->title,
                $extracted->siteName,
                $extracted->author,
                $extracted->excerpt,
                $extracted->imageUrl,
                $extracted->publishedAt,
                $this->clock->now(),
            );

            $engine = $this->voices->engine($article->getVoiceEngine());
            $prepared = $this->preparer->prepare($extracted->title, $blocks, $language, $engine->spellsIntegers());
            $voice = $this->voices->voice($engine->name, $language, $article->getVoiceGender());

            $article->prepare($prepared, $voice->name, 'rules', $this->clock->now());
            $this->articles->save($article);
        } catch (ExtractionFailed $e) {
            $this->logger->notice('Extraction of {url} failed: {reason}', ['url' => $article->getUrl(), 'reason' => $e->getMessage()]);
            $article->fail($e->reason, $this->clock->now());
            $this->articles->save($article);

            return;
        } catch (NoReadableText) {
            $article->fail(ExtractionFailed::NO_TEXT, $this->clock->now());
            $this->articles->save($article);

            return;
        }

        $this->bus->dispatch(new SynthesizeArticle($article->getId(), $article->getRevision()));
    }

    /**
     * The voice says the title, never the author line ("By Ada Lovecraft, April 12"), which
     * readability sometimes keeps as the first paragraph.
     *
     * @param list<Block> $blocks
     *
     * @return list<Block>
     */
    private static function withoutByline(array $blocks, ?string $author): array
    {
        $first = $blocks[0] ?? null;
        if (null === $first || BlockKind::Paragraph !== $first->kind || mb_strlen($first->text) > 120) {
            return $blocks;
        }

        $mentionsAuthor = null !== $author && '' !== $author && str_contains(mb_strtolower($first->text), mb_strtolower($author));
        if ($mentionsAuthor || 1 === preg_match('/^(by|par)\s+\p{Lu}/u', $first->text)) {
            return \array_slice($blocks, 1);
        }

        return $blocks;
    }
}

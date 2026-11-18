<?php

declare(strict_types=1);

namespace App\Library;

use App\Editor\SpeechEditor;
use App\Entity\Article;
use App\Extraction\ExtractedArticle;
use App\Extraction\ExtractionFailed;
use App\Message\EditArticle;
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
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Turns the content of a page into the chunks of an article, saves it and starts its synthesis
 * and its speech editor.
 *
 * The page comes from the server (ExtractArticleHandler fetches it), from the browser extension
 * (the browser extracted it, with the access of the reader), or from the transcript already
 * stored (another language, a retry): an article read in the browser, behind a login or a
 * subscription, is never fetched again by the server.
 */
final readonly class ArticlePreparation
{
    public function __construct(
        private ArticleRepository $articles,
        private HtmlBlockExtractor $blocks,
        private LanguageDetector $languages,
        private ArticlePreparer $preparer,
        private VoiceCatalog $voices,
        private SpeechEditor $editor,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ExtractionFailed for a language other than French or English
     * @throws NoReadableText   under 30 words
     */
    public function fromPage(Article $article, ExtractedArticle $page): void
    {
        $blocks = self::withoutByline($this->blocks->extract($page->html), $page->author);

        $article->describe($page->title, $page->siteName, $page->author, $page->excerpt, $page->imageUrl, $page->publishedAt, $this->clock->now());
        $this->prepare($article, $page->title, $blocks, $page->languageHint);
    }

    /**
     * Prepares the article again from its stored text, without the page.
     *
     * @throws ExtractionFailed for a language other than French or English
     * @throws NoReadableText   when nothing was stored
     */
    public function fromTranscript(Article $article): void
    {
        $blocks = [];
        foreach ($article->getParagraphs() as $segments) {
            $kind = $segments[0]->getKind();
            if (BlockKind::Title !== $kind) {
                $blocks[] = new Block($kind, implode(' ', array_map(static fn ($segment): string => $segment->getText(), $segments)));
            }
        }

        if ([] === $blocks) {
            throw new NoReadableText(0);
        }

        $this->prepare($article, $article->getDisplayTitle(), $blocks, $article->getLanguage()?->value);
    }

    /**
     * @param list<Block> $blocks
     */
    private function prepare(Article $article, string $title, array $blocks, ?string $languageHint): void
    {
        $sample = $title."\n".implode("\n", array_map(static fn (Block $block): string => $block->text, $blocks));
        $language = $article->getLanguageSetting()->forced()
            ?? $this->languages->detect($sample, $languageHint)
            ?? throw new ExtractionFailed(ExtractionFailed::UNSUPPORTED_LANGUAGE, (string) $languageHint);

        $engine = $this->voices->engine($article->getVoiceEngine());
        $prepared = $this->preparer->prepare($title, $blocks, $language, $engine->spellsIntegers());
        $voice = $this->voices->voice($engine->name, $language, $article->getVoiceGender());

        if (!$article->getSegments()->isEmpty()) {
            // The former chunks are deleted first: the new ones take the same positions.
            $article->discardSegments();
            $this->articles->save($article);
        }

        $article->prepare($prepared, $voice->name, 'rules', $this->clock->now());
        $this->articles->save($article);

        $this->bus->dispatch(new SynthesizeArticle($article->getId(), $article->getRevision()));
        if (null !== $this->editor->backend()) {
            $this->bus->dispatch(new EditArticle($article->getId(), $article->getRevision()));
        }
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

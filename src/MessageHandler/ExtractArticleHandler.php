<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Extraction\ArticleExtractor;
use App\Extraction\ExtractionFailed;
use App\Library\ArticlePreparation;
use App\Message\ExtractArticle;
use App\Repository\ArticleRepository;
use App\Text\NoReadableText;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Fetches the page, extracts the article and prepares it (ArticlePreparation). Within a second
 * or two of the click, the article has a title and chunks that can be synthesized.
 */
#[AsMessageHandler]
final readonly class ExtractArticleHandler
{
    public function __construct(
        private ArticleRepository $articles,
        private ArticleExtractor $extractor,
        private ArticlePreparation $preparation,
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
            $this->preparation->fromPage($article, $this->extractor->extract($article->getUrl()));
        } catch (ExtractionFailed $e) {
            $this->logger->notice('Extraction of {url} failed: {reason}', ['url' => $article->getUrl(), 'reason' => $e->getMessage()]);
            $article->fail($e->reason, $this->clock->now());
            $this->articles->save($article);
        } catch (NoReadableText) {
            $article->fail(ExtractionFailed::NO_TEXT, $this->clock->now());
            $this->articles->save($article);
        }
    }
}

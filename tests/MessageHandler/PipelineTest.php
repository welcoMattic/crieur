<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Article;
use App\Enum\ArticleSource;
use App\Enum\ArticleStatus;
use App\Enum\VoiceGender;
use App\Library\ArticleQueue;
use App\Message\ExtractArticle;
use App\Message\SynthesizeArticle;
use App\MessageHandler\ExtractArticleHandler;
use App\MessageHandler\SynthesizeArticleHandler;
use App\Repository\ArticleRepository;
use App\Speech\AudioStore;
use App\Speech\Wav;
use App\Tests\RecreatesSchema;
use App\Text\BlockKind;
use App\Text\Language;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The whole pipeline, from a URL to audio, with a fake web page and a fake voice.
 */
final class PipelineTest extends KernelTestCase
{
    use RecreatesSchema;

    protected function setUp(): void
    {
        self::bootKernel();
        self::recreateSchema(self::getContainer()->get(EntityManagerInterface::class));
    }

    #[Test]
    public function itTurnsAnArticleIntoPlayableAudioTitleFirst(): void
    {
        $this->servePage((string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html'));

        $article = $this->queue()->queue('https://example.org/essay', ArticleSource::Web);
        $this->runPipeline();

        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Ready, $article->getStatus());
        static::assertSame(Language::English, $article->getLanguage());
        static::assertSame('Why we read slower than we listen', $article->getTitle());
        static::assertSame('The Field Notes', $article->getSiteName());
        static::assertSame('Hadley', $article->getVoiceName());
        static::assertSame('rules', $article->getEditor());
        static::assertGreaterThan(80, $article->getWordCount());

        $segments = $article->getSegments()->toArray();
        static::assertSame(BlockKind::Title, $segments[0]->getKind());
        static::assertSame('Why we read slower than we listen', $segments[0]->getText());
        static::assertSame($article->getSegments()->count(), $article->countReadySegments());

        $store = self::getContainer()->get(AudioStore::class);
        foreach ($segments as $segment) {
            $key = $segment->getAudioKey();
            static::assertNotNull($key);
            static::assertSame($segment->getDurationMs(), Wav::fromBytes($store->read($key))->durationMs());
        }
        static::assertGreaterThan(10, $article->getAudioSeconds());
    }

    #[Test]
    public function itReadsTheRestWithAnotherVoice(): void
    {
        $this->servePage((string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html'));
        $article = $this->queue()->queue('https://example.org/essay', ArticleSource::Mcp);
        $this->runPipeline();

        $this->queue()->changeVoice($this->reload($article), VoiceGender::Male);
        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Synthesizing, $article->getStatus());
        static::assertSame(0, $article->countReadySegments());

        $this->runPipeline();

        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Ready, $article->getStatus());
        static::assertSame('Preston', $article->getVoiceName());
    }

    #[Test]
    public function itReportsAPageWithoutEnoughText(): void
    {
        $this->servePage('<html lang="en"><head><title>Short</title></head><body><article><p>Too short to be read aloud.</p></article></body></html>');

        $article = $this->queue()->queue('https://example.org/short', ArticleSource::Web);
        $this->runPipeline();

        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Failed, $article->getStatus());
        static::assertSame('extraction.no_text', $article->getFailure());
    }

    #[Test]
    public function itReportsAnHttpError(): void
    {
        self::getContainer()->set('http_client', new MockHttpClient(new MockResponse('Not found', ['http_code' => 404])));

        $article = $this->queue()->queue('https://example.org/missing', ArticleSource::Web);
        $this->runPipeline();

        static::assertSame('extraction.http_error', $this->reload($article)->getFailure());
    }

    private function servePage(string $html): void
    {
        self::getContainer()->set('http_client', new MockHttpClient(static fn (): MockResponse => new MockResponse($html, ['response_headers' => ['content-type' => 'text/html; charset=utf-8']])));
    }

    private function queue(): ArticleQueue
    {
        return self::getContainer()->get(ArticleQueue::class);
    }

    /**
     * Plays the role of the two queue workers until both queues are empty.
     */
    private function runPipeline(): void
    {
        $extract = self::getContainer()->get('messenger.transport.extract');
        $synthesize = self::getContainer()->get('messenger.transport.synthesize');
        \assert($extract instanceof InMemoryTransport && $synthesize instanceof InMemoryTransport);

        for ($round = 0; $round < 10; ++$round) {
            $envelopes = [...$extract->get(), ...$synthesize->get()];
            if ([] === $envelopes) {
                return;
            }
            foreach ($envelopes as $envelope) {
                $message = $envelope->getMessage();
                match (true) {
                    $message instanceof ExtractArticle => self::getContainer()->get(ExtractArticleHandler::class)($message),
                    $message instanceof SynthesizeArticle => self::getContainer()->get(SynthesizeArticleHandler::class)($message),
                    default => throw new \LogicException(\sprintf('Unexpected message %s.', $message::class)),
                };
                $envelope->getMessage() instanceof ExtractArticle ? $extract->ack($envelope) : $synthesize->ack($envelope);
            }
        }

        static::fail('The pipeline did not settle in 10 rounds.');
    }

    private function reload(Article $article): Article
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = self::getContainer()->get(ArticleRepository::class)->get($article->getId());
        static::assertNotNull($reloaded);

        return $reloaded;
    }
}

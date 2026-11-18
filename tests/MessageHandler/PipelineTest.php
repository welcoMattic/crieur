<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Demo\LibraryReset;
use App\Editor\SpeechEditor;
use App\Entity\Article;
use App\Enum\ArticleSource;
use App\Enum\ArticleStatus;
use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use App\Library\ArticleQueue;
use App\Message\EditArticle;
use App\Message\ExtractArticle;
use App\Message\SynthesizeArticle;
use App\MessageHandler\EditArticleHandler;
use App\MessageHandler\ExtractArticleHandler;
use App\MessageHandler\SynthesizeArticleHandler;
use App\Repository\ArticleRepository;
use App\Speech\AudioStore;
use App\Speech\VoiceCatalog;
use App\Speech\Wav;
use App\Tests\RecreatesSchema;
use App\Text\BlockKind;
use App\Text\Language;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\EventDispatcher;
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
    public function theEditorRewritesTheTextForTheEarAheadOfTheVoice(): void
    {
        $this->useEditor(self::faithfulEditor());
        $this->servePage((string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html'));

        $article = $this->queue()->queue('https://example.org/essay', ArticleSource::Web);
        $this->runPipeline();

        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Ready, $article->getStatus());
        static::assertSame('mistral-small-latest', $article->getEditor());
        static::assertSame('An essay on listening.', $article->getSummary());

        $segments = array_values($article->getSegments()->toArray());
        static::assertFalse($segments[0]->isEdited(), 'The title keeps the rules.');
        static::assertFalse($segments[1]->isEdited(), 'The first chunk of the body keeps the rules: the voice starts at once.');
        foreach (\array_slice($segments, 2) as $segment) {
            static::assertTrue($segment->isEdited());
            static::assertStringStartsWith('Heard: ', $segment->getSpoken());
            static::assertTrue($segment->isReady());
        }
    }

    #[Test]
    public function theVoiceMayOutrunTheEditorAndTheArticleStaysConsistent(): void
    {
        $this->useEditor(self::faithfulEditor());
        $this->servePage((string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html'));

        // The synthesis worker reads everything with the rules before the editor answers.
        $article = $this->queue()->queue('https://example.org/essay', ArticleSource::Web);
        $this->runPipeline(['extract', 'synthesize']);
        static::assertSame(ArticleStatus::Ready, $this->reload($article)->getStatus());

        // Then the editor rewrites: the article is in progress again, until the new text has its audio.
        $this->runPipeline(['edit']);
        static::assertSame(ArticleStatus::Synthesizing, $this->reload($article)->getStatus());
        $this->runPipeline(['synthesize']);

        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Ready, $article->getStatus());
        foreach (\array_slice(array_values($article->getSegments()->toArray()), 2) as $segment) {
            static::assertStringStartsWith('Heard: ', $segment->getSpoken());
            static::assertSame(AudioStore::key(self::getContainer()->get(VoiceCatalog::class)->voice('phonon', Language::English, VoiceGender::Female), $segment->getSpoken()), $segment->getAudioKey());
        }
    }

    #[Test]
    public function anEditorThatStopsAnsweringLeavesNoChunkWithoutAudio(): void
    {
        $faithful = self::faithfulEditor();
        $calls = 0;
        $this->useEditor(static function (Model $model, array|string|object $input) use ($faithful, &$calls): string {
            if (++$calls > 1) {
                throw new \RuntimeException('The editor is gone.');
            }

            return $faithful($model, $input);
        });
        $this->servePage((string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html'));

        // The voice reads everything first; the editor rewrites one batch, then stops answering.
        $article = $this->queue()->queue('https://example.org/essay', ArticleSource::Web);
        $this->runPipeline(['extract', 'synthesize']);
        $this->runPipeline(['edit', 'synthesize']);
        static::assertGreaterThan(1, $calls);

        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Ready, $article->getStatus());
        static::assertSame($article->getSegments()->count(), $article->countReadySegments());
    }

    #[Test]
    public function aVoiceChangeKeepsTheEditorGoing(): void
    {
        $this->useEditor(self::faithfulEditor());
        $this->servePage((string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html'));
        $article = $this->queue()->queue('https://example.org/essay', ArticleSource::Web);
        $this->runPipeline(['extract', 'synthesize']);

        $this->queue()->changeVoice($this->reload($article), VoiceGender::Male, 3);
        $this->runPipeline();

        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Ready, $article->getStatus());
        static::assertSame('Preston', $article->getVoiceName());
        foreach (\array_slice(array_values($article->getSegments()->toArray()), 2) as $segment) {
            static::assertTrue($segment->isEdited(), 'The editor goes on after a voice change.');
        }
    }

    #[Test]
    public function anotherLanguagePreparesTheArticleAgain(): void
    {
        $this->servePage((string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html'));
        $article = $this->queue()->queue('https://example.org/essay', ArticleSource::Web);
        $this->runPipeline();

        $this->queue()->restart($this->reload($article), LanguageSetting::French);
        $this->runPipeline();

        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Ready, $article->getStatus());
        static::assertSame(Language::French, $article->getLanguage());
        static::assertSame('Tiphaine', $article->getVoiceName());
    }

    #[Test]
    public function aPassageTheListenerRemovedIsNotReadEvenAfterAnotherPreparation(): void
    {
        $this->servePage((string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html'));
        $article = $this->queue()->queue('https://example.org/essay', ArticleSource::Web);
        $this->runPipeline(['extract']);

        // Removed before the voice reaches it: a silence, never the voice.
        $article = $this->reload($article);
        $firstParagraph = array_values($article->getParagraphs())[1];
        $removedText = $firstParagraph[0]->getText();
        $this->queue()->setRemoved($article, array_map(static fn ($segment): int => $segment->getPosition(), $firstParagraph), true);
        $this->runPipeline();

        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Ready, $article->getStatus());
        foreach ($article->getSegments() as $segment) {
            if ($segment->isRemoved()) {
                static::assertLessThanOrEqual(1, $segment->getDurationMs(), 'a removed chunk is a silence');
            }
        }
        static::assertSame(\count($firstParagraph), $article->countRemovedSegments());
        static::assertNotContains($removedText, array_map(static fn (array $segments): string => $segments[0]->getText(), $article->getParagraphs(withRemoved: false)));

        // Another language prepares the page again: the removed passage stays out.
        $this->queue()->restart($article, LanguageSetting::French);
        $this->runPipeline();
        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Ready, $article->getStatus());
        foreach ($article->getSegments() as $segment) {
            static::assertStringNotContainsString($removedText, $segment->getText());
        }

        // Put back: synthesized again with the voice.
        $segment = $article->getSegments()[1];
        static::assertNotNull($segment);
        $this->queue()->setRemoved($article, [1], true);
        $this->queue()->setRemoved($this->reload($article), [1], false);
        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Synthesizing, $article->getStatus());
        $this->runPipeline();
        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Ready, $article->getStatus());
        static::assertSame(0, $article->countRemovedSegments());
        static::assertGreaterThan(1, $article->getSegments()[1]?->getDurationMs());
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
    public function aVoiceChangeWhileAChunkIsSynthesizedKeepsTheOldVoiceOut(): void
    {
        // The listener picks another voice while the worker waits for the audio of a chunk.
        $state = new class {
            public ?Article $article = null;
            public bool $changed = false;
        };
        self::getContainer()->set('crieur.platform.speech', MockPlatformFactory::createPlatform(function (Model $model, array|string|object $payload) use ($state): BinaryResult {
            if (!$state->changed && $state->article instanceof Article) {
                $state->changed = true;
                $this->queue()->changeVoice($this->articleOf($state->article), VoiceGender::Male);
            }

            return new BinaryResult(Wav::silence(60 * mb_strlen(\is_string($payload) ? $payload : '-'))->toBytes(), 'audio/wav');
        }));
        // A text of its own: audio the store holds already from another test is not synthesized again.
        $page = str_replace('The reason is not speed', 'The reason, '.strtr(bin2hex(random_bytes(6)), '0123456789', 'ghijklmnop').', is not speed', (string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html'));
        $this->servePage($page);
        $article = $state->article = $this->queue()->queue('https://example.org/essay', ArticleSource::Web);
        $this->runPipeline();
        static::assertTrue($state->changed);

        $article = $this->reload($article);
        static::assertSame(ArticleStatus::Ready, $article->getStatus());
        $voice = self::getContainer()->get(VoiceCatalog::class)->voice($article->getVoiceEngine(), Language::English, VoiceGender::Male);
        foreach ($article->getSegments() as $segment) {
            if ('' !== trim($segment->getSpoken())) {
                static::assertSame(AudioStore::key($voice, $segment->getSpoken()), $segment->getAudioKey(), 'Every chunk is read by the new voice.');
            }
        }
    }

    #[Test]
    public function theDemoMayEmptyTheLibraryWhileAnArticleIsRead(): void
    {
        // The reset of the public demo runs in its own process while the worker waits for audio.
        $state = new class {
            public bool $article = false;
            public int $removed = -1;
        };
        self::getContainer()->set('crieur.platform.speech', MockPlatformFactory::createPlatform(function (Model $model, array|string|object $payload) use ($state): BinaryResult {
            if ($state->article && -1 === $state->removed) {
                $state->removed = self::getContainer()->get(LibraryReset::class)->reset();
            }

            return new BinaryResult(Wav::silence(60 * mb_strlen(\is_string($payload) ? $payload : '-'))->toBytes(), 'audio/wav');
        }));
        $page = str_replace('The reason is not speed', 'The reason, '.strtr(bin2hex(random_bytes(6)), '0123456789', 'ghijklmnop').', is not speed', (string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html'));
        $this->servePage($page);
        $this->queue()->queue('https://example.org/essay', ArticleSource::Web);
        $state->article = true;
        $this->runPipeline();

        static::assertSame(1, $state->removed);
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM article'));
        static::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM segment'));
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

    /**
     * An editor that answers every chunk faithfully, prefixed with "Heard: ".
     *
     * @return \Closure(Model, array<mixed>|string|object): string
     */
    private static function faithfulEditor(): \Closure
    {
        return static function (Model $model, array|string|object $input): string {
            \assert(\is_array($input));
            // The platform receives the normalized messages: find the request of the editor in them.
            $request = null;
            array_walk_recursive($input, static function (mixed $value) use (&$request): void {
                if (\is_string($value) && str_contains($value, '"chunks"')) {
                    $request = json_decode($value, true, flags: \JSON_THROW_ON_ERROR);
                }
            });
            \assert(\is_array($request));

            return (string) json_encode([
                'chunks' => array_map(static fn (array $chunk): array => ['id' => $chunk['id'], 'spoken' => 'Heard: '.$chunk['text']], $request['chunks']),
                'summary' => 'An essay on listening.',
            ]);
        };
    }

    /**
     * @param \Closure(Model, array<mixed>|string|object): string $answer
     */
    private function useEditor(\Closure $answer): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PlatformSubscriber());
        $agent = new Agent(MockPlatformFactory::createPlatform($answer, eventDispatcher: $dispatcher), 'mock-model');
        self::getContainer()->set(SpeechEditor::class, new SpeechEditor(
            new ServiceLocator(['mistral' => static fn () => $agent, 'ollama' => static fn () => $agent]),
            'mistral',
            'key',
            new NullLogger(),
        ));
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
    /**
     * @param list<string> $queues the order in which the queues are drained in each round
     */
    private function runPipeline(array $queues = ['extract', 'edit', 'synthesize']): void
    {
        $transports = [];
        foreach ($queues as $name) {
            $transport = self::getContainer()->get('messenger.transport.'.$name);
            \assert($transport instanceof InMemoryTransport);
            $transports[] = $transport;
        }

        for ($round = 0; $round < 20; ++$round) {
            $idle = true;
            foreach ($transports as $transport) {
                foreach ($transport->get() as $envelope) {
                    $idle = false;
                    $message = $envelope->getMessage();
                    match (true) {
                        $message instanceof ExtractArticle => self::getContainer()->get(ExtractArticleHandler::class)($message),
                        $message instanceof EditArticle => self::getContainer()->get(EditArticleHandler::class)($message),
                        $message instanceof SynthesizeArticle => self::getContainer()->get(SynthesizeArticleHandler::class)($message),
                        default => throw new \LogicException(\sprintf('Unexpected message %s.', $message::class)),
                    };
                    $transport->ack($envelope);
                }
            }
            if ($idle) {
                return;
            }
        }

        static::fail('The pipeline did not settle in 20 rounds.');
    }

    /**
     * The article as the entity manager of the workers holds it, without clearing it.
     */
    private function articleOf(Article $article): Article
    {
        $managed = self::getContainer()->get(ArticleRepository::class)->get($article->getId());
        static::assertNotNull($managed);

        return $managed;
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

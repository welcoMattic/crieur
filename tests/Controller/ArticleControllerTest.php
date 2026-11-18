<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Article;
use App\Enum\ArticleSource;
use App\Enum\ArticleStatus;
use App\Enum\VoiceGender;
use App\Message\ExtractArticle;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class ArticleControllerTest extends ControllerTestCase
{
    public function testAddingAnArticleQueuesItAndOpensItsPage(): void
    {
        $crawler = $this->client->request('GET', '/');
        $form = $crawler->selectButton('Read aloud')->form(['article[url]' => 'example.org/a-long-read', 'article[voice]' => 'male']);

        $this->client->submit($form, serverParameters: self::sameOrigin());

        self::assertResponseStatusCodeSame(303);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('~^/articles/[0-9a-f-]{36}\?play=1$~', $location);

        $articles = $this->entityManager()->getRepository(Article::class)->findAll();
        self::assertCount(1, $articles);
        self::assertSame('https://example.org/a-long-read', $articles[0]->getUrl());
        self::assertSame(ArticleSource::Web, $articles[0]->getSource());
        self::assertSame(VoiceGender::Male, $articles[0]->getVoiceGender());

        $transport = static::getContainer()->get('messenger.transport.extract');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertCount(1, $transport->getSent());
        self::assertInstanceOf(ExtractArticle::class, $transport->getSent()[0]->getMessage());
    }

    public function testAnEmptyLinkIsRefusedInline(): void
    {
        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->selectButton('Read aloud')->form(['article[url]' => '  ']), serverParameters: self::sameOrigin());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', 'Paste the link to an article.');
        self::assertSelectorExists('input[name="article[url]"][aria-invalid="true"]');
        self::assertCount(0, $this->entityManager()->getRepository(Article::class)->findAll());
    }

    public function testTheErrorIsTranslated(): void
    {
        $server = ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9'];
        $crawler = $this->client->request('GET', '/', server: $server);
        $this->client->submit($crawler->selectButton('Lire à voix haute')->form(['article[url]' => 'ftp://example.org/file']), serverParameters: self::sameOrigin() + $server);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.field-error', "Crieur ne lit que des pages web\u{A0}: le lien doit commencer par http ou https.");
    }

    public function testAFormFromAnotherSiteIsRefused(): void
    {
        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->selectButton('Read aloud')->form(['article[url]' => 'https://example.org/']), serverParameters: self::crossSite());

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->entityManager()->getRepository(Article::class)->findAll());
    }

    public function testTheReadAlongPageShowsTheTextAndWhatTheVoiceReads(): void
    {
        $article = $this->createArticle();

        $crawler = $this->client->request('GET', '/articles/'.$article->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'On listening');
        self::assertSelectorTextContains('.reader__summary', 'A short walk');
        self::assertSelectorTextContains('.models', 'ministral-3:8b');
        self::assertSame('phonon:40m-multilingual', $crawler->filter('.models code')->first()->text());
        self::assertCount(4, $crawler->filter('.seg[data-position]'));
        self::assertCount(1, $crawler->filter('.seg[data-rewritten]'));
        self::assertSelectorTextContains('.seg[data-rewritten] .seg__spoken', 'three and a half kilometres');
        self::assertSelectorTextContains('.transcript-bar .toggle', 'Show what the voice reads');
        self::assertSelectorTextContains('.transcript-bar .toggle[data-reader-target="editToggle"]', 'Choose what is read');
    }

    public function testTheStatusDescribesEveryChunk(): void
    {
        $article = $this->createArticle(ready: 2);

        $this->client->request('GET', \sprintf('/articles/%s/status', $article->getId()));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $status = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($status);
        foreach (['id', 'status', 'inProgress', 'playable', 'failure', 'failureMessage', 'title', 'site', 'url', 'page', 'language', 'languageSetting', 'voice', 'voiceGender', 'editor', 'paragraphCount', 'revision', 'readySegments', 'audioSeconds', 'segments'] as $key) {
            self::assertArrayHasKey($key, $status);
        }
        self::assertSame('synthesizing', $status['status']);
        self::assertSame('Hadley', $status['voice']);
        self::assertSame(2, $status['readySegments']);
        self::assertIsArray($status['segments']);
        self::assertCount(4, $status['segments']);
        self::assertSame(['position', 'paragraph', 'removed', 'ready', 'url', 'durationMs', 'pauseAfterMs', 'chars', 'edited', 'spokenHash'], array_keys($status['segments'][0]));
        self::assertTrue($status['segments'][1]['ready']);
        self::assertStringContainsString('/segments/1.wav?k=', $status['segments'][1]['url']);
        self::assertSame(\strlen('It was three and a half kilometres away.'), $status['segments'][1]['chars']);
        self::assertFalse($status['segments'][2]['ready']);
        self::assertNull($status['segments'][2]['url']);
    }

    public function testTheAudioOfAChunkIsServedWithRangesAndALongCache(): void
    {
        $article = $this->createArticle(ready: 2);
        $first = $article->getSegments()->first();
        self::assertNotFalse($first);
        $key = $first->getAudioKey();
        $url = \sprintf('/articles/%s/segments/0.wav?k=%s', $article->getId(), $key);

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'audio/wav');
        self::assertResponseHeaderSame('Accept-Ranges', 'bytes');
        $cacheControl = (string) $this->client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('max-age=31536000', $cacheControl);
        self::assertStringContainsString('immutable', $cacheControl);
        self::assertStringContainsString('public', $cacheControl);

        $this->client->request('GET', $url, server: ['HTTP_RANGE' => 'bytes=0-99']);
        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('Content-Range', 'bytes 0-99/3244');

        $this->client->request('GET', \sprintf('/articles/%s/segments/0.wav?k=%s', $article->getId(), str_repeat('0', 32)));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', \sprintf('/articles/%s/segments/3.wav', $article->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testChangesNeedAFormOfThisSite(): void
    {
        $article = $this->createArticle();
        $id = $article->getId();

        foreach ([['voice', ['voice' => 'male']], ['language', ['language' => 'fr']], ['passages', ['positions' => '1', 'removed' => '1']], ['retry', []], ['delete', []]] as [$action, $data]) {
            $this->client->request('POST', \sprintf('/articles/%s/%s', $id, $action), $data);
            self::assertResponseStatusCodeSame(403, $action.' without a token');

            $this->client->request('POST', \sprintf('/articles/%s/%s', $id, $action), $data + ['_token' => 'csrf-token'], server: self::crossSite());
            self::assertResponseStatusCodeSame(403, $action.' from another site');
        }

        self::assertNotNull($this->reload($article));
    }

    public function testTheVoiceChangesAndTheArticleIsSynthesizedAgain(): void
    {
        $article = $this->createArticle();

        $this->client->request('POST', \sprintf('/articles/%s/voice', $article->getId()), ['voice' => 'male', '_token' => 'csrf-token'], server: self::sameOrigin());

        self::assertResponseRedirects(\sprintf('/articles/%s', $article->getId()), 303);
        $article = $this->reload($article);
        self::assertNotNull($article);
        self::assertSame(VoiceGender::Male, $article->getVoiceGender());
        self::assertSame('Preston', $article->getVoiceName());
        self::assertSame(0, $article->countReadySegments());
    }

    public function testAPassageIsLeftOutOfTheReadingThenPutBack(): void
    {
        $article = $this->createArticle();
        $passages = \sprintf('/articles/%s/passages', $article->getId());

        $this->client->request('POST', $passages, ['positions' => '1,2', 'removed' => '1', '_token' => 'csrf-token'], server: self::sameOrigin() + ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', \sprintf('/articles/%s/status', $article->getId()));
        $status = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($status);
        self::assertSame('ready', $status['status']);
        self::assertSame([false, true, true, false], array_column($status['segments'], 'removed'));
        self::assertSame(0, $status['segments'][1]['durationMs']);
        self::assertSame(0, $status['segments'][1]['pauseAfterMs']);
        self::assertTrue($status['segments'][1]['ready']);

        $crawler = $this->client->request('GET', '/articles/'.$article->getId());
        self::assertCount(2, $crawler->filter('.seg.is-removed'));
        self::assertCount(1, $crawler->filter('.para.is-removed .para__toggle[aria-pressed="true"]'));

        // A plain form goes back to the article; the voice reads the chunk again.
        $this->client->request('POST', $passages, ['positions' => '2', 'removed' => '0', '_token' => 'csrf-token'], server: self::sameOrigin());
        self::assertResponseRedirects(\sprintf('/articles/%s', $article->getId()), 303);
        $article = $this->reload($article);
        self::assertNotNull($article);
        self::assertSame(ArticleStatus::Synthesizing, $article->getStatus());
        self::assertSame(1, $article->countRemovedSegments());
        self::assertFalse($article->getSegments()[2]?->isReady());

        $this->client->request('POST', $passages, ['positions' => 'all', 'removed' => '1', '_token' => 'csrf-token'], server: self::sameOrigin());
        self::assertResponseStatusCodeSame(400);
    }

    public function testDeletingAnArticleReturnsToTheLibrary(): void
    {
        $article = $this->createArticle();

        $this->client->request('POST', \sprintf('/articles/%s/delete', $article->getId()), ['_token' => 'csrf-token'], server: self::sameOrigin());

        self::assertResponseRedirects('/', 303);
        self::assertNull($this->reload($article));
    }
}

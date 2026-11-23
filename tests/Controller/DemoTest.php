<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Demo\PublicDemo;
use App\Entity\Article;
use App\Enum\ArticleSource;
use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use App\Twig\Demo;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The public demo (APP_ENV=demo), as its visitors meet it.
 */
final class DemoTest extends ControllerTestCase
{
    private const string EXTENSION = 'chrome-extension://abcdefghijklmnopabcdefghijklmnop';

    public function testTheHomePageTellsWhenTheLibraryIsEmptiedAndTheLimits(): void
    {
        $this->useDemo(new MockClock('2026-11-18 15:45:00 UTC'));

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.notice--demo', 'Public demo');
        self::assertSelectorTextContains('.notice--demo', 'next at 18:00 UTC (in 2 h 15 min)');
        self::assertSelectorTextContains('.notice--demo', 'Each visitor may add 2 articles an hour, of up to 400 characters.');
        self::assertSelectorTextContains('.site-footer__host', 'This demo is hosted by Clever Cloud: thank you!');
        self::assertSelectorExists('.site-footer__host a[href="https://www.clever-cloud.com"]');
        self::assertStringContainsString('<script>window.demoAnalytics = true;</script>', (string) $this->client->getResponse()->getContent(), 'The head of the operator, on the demo only.');
    }

    public function testTheHomePageSaysNothingOutsideTheDemo(): void
    {
        $this->client->disableReboot();
        self::getContainer()->set(Demo::class, new Demo(self::getContainer()->get(PublicDemo::class), '<script>window.demoAnalytics = true;</script>'));
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.notice--demo');
        self::assertSelectorNotExists('.site-footer__host');
        self::assertStringNotContainsString('demoAnalytics', (string) $this->client->getResponse()->getContent());
    }

    public function testAVisitorWhoAddedEnoughWaits(): void
    {
        $this->useDemo(maxCharacters: 25_000);
        self::getContainer()->set('http_client', new MockHttpClient(static fn () => throw new \LogicException('The server must not fetch the page.')));

        $this->post();
        self::assertResponseStatusCodeSame(201);
        $this->post();
        self::assertResponseStatusCodeSame(201);
        $this->post();

        self::assertResponseStatusCodeSame(429);
        self::assertGreaterThan(0, (int) $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame('demo.too_many', $this->json()['error']);
        self::assertStringContainsString('try again within the hour', (string) $this->json()['message']);
    }

    public function testReadingAPageAgainInAnotherLanguageCountsAsAnArticle(): void
    {
        $this->useDemo();
        $article = $this->createArticle();
        $language = \sprintf('/articles/%s/language', $article->getId());

        // The queue of the workers is emptied before each request: what one request sent is counted at once.
        foreach (['fr' => 1, 'en' => 1, 'auto' => 0] as $setting => $fetches) {
            $this->client->request('POST', $language, ['language' => $setting, '_token' => 'csrf-token'], server: self::sameOrigin());
            self::assertResponseRedirects(\sprintf('/articles/%s', $article->getId()), 303);
            $transport = static::getContainer()->get('messenger.transport.extract');
            self::assertInstanceOf(InMemoryTransport::class, $transport);
            self::assertCount($fetches, $transport->getSent(), \sprintf('The page is fetched again for "%s".', $setting));
        }

        $this->client->followRedirect();
        self::assertSelectorTextContains('.field-error', 'The public demo takes no more articles from you for now');
        $this->entityManager()->clear();
        self::assertSame(LanguageSetting::English, $this->entityManager()->find(Article::class, $article->getId())?->getLanguageSetting());
    }

    public function testTryingAFailedPageAgainCountsAsAnArticle(): void
    {
        $this->useDemo();
        $article = new Article('https://example.org/down', LanguageSetting::Auto, VoiceGender::Female, 'phonon', ArticleSource::Web);
        $article->fail('extraction.network');
        $this->entityManager()->persist($article);
        $this->entityManager()->flush();

        foreach ([1, 1, 0] as $fetches) {
            $this->client->request('POST', \sprintf('/articles/%s/retry', $article->getId()), ['_token' => 'csrf-token'], server: self::sameOrigin());
            $transport = static::getContainer()->get('messenger.transport.extract');
            self::assertInstanceOf(InMemoryTransport::class, $transport);
            self::assertCount($fetches, $transport->getSent());
        }

        $this->client->followRedirect();
        self::assertSelectorTextContains('.field-error', 'The public demo takes no more articles from you for now');
    }

    public function testAnArticleTooLongForTheDemoIsRefused(): void
    {
        $this->useDemo(maxCharacters: 100);
        self::getContainer()->set('http_client', new MockHttpClient(static fn () => throw new \LogicException('The server must not fetch the page.')));

        $this->post();

        self::assertResponseStatusCodeSame(422);
        self::assertSame('extraction.too_long_for_demo', $this->json()['error']);
    }

    private function useDemo(?MockClock $clock = null, int $maxCharacters = 400): void
    {
        // The services of the demo stay for the whole test: no new kernel between two requests.
        $this->client->disableReboot();
        $storage = new InMemoryStorage();
        self::getContainer()->set(PublicDemo::class, $demo = new PublicDemo(
            true,
            new RateLimiterFactory(['id' => 'demo_visitor', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '1 hour'], $storage),
            new RateLimiterFactory(['id' => 'demo_all', 'policy' => 'sliding_window', 'limit' => 30, 'interval' => '1 hour'], $storage),
            self::getContainer()->get(RequestStack::class),
            $clock ?? new MockClock(),
            2,
            $maxCharacters,
        ));
        self::getContainer()->set(Demo::class, new Demo($demo, '<script>window.demoAnalytics = true;</script>'));
    }

    private function post(): void
    {
        $html = (string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html');
        if (1 !== preg_match('~<article>(.*)</article>~s', $html, $match)) {
            self::fail('The fixture has no article element.');
        }

        $this->client->request('POST', '/api/articles', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => self::EXTENSION], content: json_encode([
            'url' => 'https://example.org/essay',
            'title' => 'Why we read slower than we listen',
            'content' => '<div>'.$match[1].'</div>',
            'lang' => 'en',
        ], \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);

        /** @var array<string, mixed> $answer */
        return $answer;
    }
}

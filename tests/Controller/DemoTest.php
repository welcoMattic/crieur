<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Demo\PublicDemo;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\RequestStack;
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
    }

    public function testTheHomePageSaysNothingOutsideTheDemo(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.notice--demo');
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
        self::getContainer()->set(PublicDemo::class, new PublicDemo(
            true,
            new RateLimiterFactory(['id' => 'demo_visitor', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '1 hour'], $storage),
            new RateLimiterFactory(['id' => 'demo_all', 'policy' => 'sliding_window', 'limit' => 30, 'interval' => '1 hour'], $storage),
            self::getContainer()->get(RequestStack::class),
            $clock ?? new MockClock(),
            2,
            $maxCharacters,
        ));
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

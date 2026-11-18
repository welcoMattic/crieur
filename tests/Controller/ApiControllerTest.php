<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Enum\ArticleSource;
use App\Enum\ArticleStatus;
use App\Repository\ArticleRepository;
use App\Text\Language;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The API of the browser extension: the browser extracted the article, the server never fetches it.
 */
final class ApiControllerTest extends ControllerTestCase
{
    private const string EXTENSION = 'chrome-extension://abcdefghijklmnopabcdefghijklmnop';

    /**
     * @var array<string, array{mixed, mixed}>
     */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        // The server must never fetch a page that the browser already read.
        self::getContainer()->set('http_client', new MockHttpClient(static fn () => throw new \LogicException('The server must not fetch the page.')));
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$env, $server]) {
            $_ENV[$name] = $env;
            $_SERVER[$name] = $server;
        }

        parent::tearDown();
    }

    public function testTheExtensionAddsAnArticleItReadInTheBrowser(): void
    {
        $this->post(self::article());

        self::assertResponseStatusCodeSame(201);
        $answer = $this->json();
        self::assertSame('synthesizing', $answer['status']);
        self::assertSame('Why we read slower than we listen', $answer['title']);
        self::assertSame('en', $answer['language']);
        self::assertStringEndsWith('/articles/'.$answer['id'], $answer['listenUrl']);

        $article = $this->articles()->latest()[0];
        self::assertSame(ArticleSource::Extension, $article->getSource());
        self::assertSame('The Field Notes', $article->getSiteName());
        self::assertGreaterThan(5, $article->getSegments()->count());
    }

    public function testThePingTellsWhetherATokenIsNeeded(): void
    {
        $this->client->request('GET', '/api/ping', server: ['HTTP_ORIGIN' => self::EXTENSION]);

        self::assertResponseIsSuccessful();
        self::assertSame(['name' => 'Crieur', 'tokenRequired' => false, 'voiceEngine' => 'phonon', 'editor' => null], $this->json());
    }

    public function testAnotherWebPageCannotAddArticles(): void
    {
        $this->post(self::article(), origin: 'https://evil.example');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->articles()->latest());
    }

    public function testOnlyJsonIsAccepted(): void
    {
        $this->client->request('POST', '/api/articles', self::article(), server: ['HTTP_ORIGIN' => self::EXTENSION]);

        self::assertResponseStatusCodeSame(415);
        self::assertSame([], $this->articles()->latest());
    }

    public function testTheTokenIsRequiredOnceSet(): void
    {
        $this->setEnv('CRIEUR_API_TOKEN', 'a-long-random-token');

        $this->post(self::article());
        self::assertResponseStatusCodeSame(401);

        $this->post(self::article(), token: 'a-long-random-token');
        self::assertResponseStatusCodeSame(201);
    }

    public function testAPageWithoutEnoughTextIsRefusedAndNotKept(): void
    {
        $this->post(['content' => '<p>Too short to be read aloud.</p>'] + self::article());

        self::assertResponseStatusCodeSame(422);
        self::assertSame('extraction.no_text', $this->json()['error']);
        self::assertSame([], $this->articles()->latest());
    }

    public function testAnInvalidPayloadIsRefused(): void
    {
        $this->post(['url' => 'file:///etc/passwd'] + self::article());

        self::assertResponseStatusCodeSame(422);
    }

    public function testAnotherLanguageIsPreparedFromTheStoredTextWithoutFetchingThePage(): void
    {
        $this->post(self::article());
        $article = $this->articles()->latest()[0];
        $segments = $article->getSegments()->count();

        $this->client->request('POST', \sprintf('/articles/%s/language', $article->getId()), ['language' => 'fr', '_token' => 'csrf-token'], server: self::sameOrigin());

        self::assertResponseRedirects();
        $this->entityManager()->clear();
        $article = $this->articles()->get($article->getId());
        self::assertNotNull($article);
        self::assertSame(ArticleStatus::Synthesizing, $article->getStatus());
        self::assertSame(Language::French, $article->getLanguage());
        self::assertSame('Tiphaine', $article->getVoiceName());
        self::assertGreaterThanOrEqual($segments - 2, $article->getSegments()->count());
        self::assertSame('Why we read slower than we listen', $article->getTitle());
    }

    /**
     * @return array<string, string>
     */
    private static function article(): array
    {
        $html = (string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html');
        if (1 !== preg_match('~<article>(.*)</article>~s', $html, $match)) {
            self::fail('The fixture has no article element.');
        }

        return [
            'url' => 'https://example.org/essay',
            'title' => 'Why we read slower than we listen',
            'content' => '<div>'.$match[1].'</div>',
            'siteName' => 'The Field Notes',
            'byline' => 'Ada Lovecraft',
            'lang' => 'en',
            'excerpt' => 'Listening frees the eyes.',
        ];
    }

    /**
     * @param array<string, string> $payload
     */
    private function post(array $payload, string $origin = self::EXTENSION, ?string $token = null): void
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => $origin];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }

        $this->client->request('POST', '/api/articles', server: $server, content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function articles(): ArticleRepository
    {
        return self::getContainer()->get(ArticleRepository::class);
    }

    private function setEnv(string $name, string $value): void
    {
        $this->saved[$name] ??= [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
        $_ENV[$name] = $_SERVER[$name] = $value;
        // The kernel reads its environment when it boots: start it again.
        self::ensureKernelShutdown();
        $this->client = static::createClient();
    }
}

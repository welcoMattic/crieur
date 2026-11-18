<?php

declare(strict_types=1);

namespace App\Extraction;

use App\Text\HtmlBlockExtractor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a web page and extracts its article with Defuddle, the engine of Obsidian Web Clipper,
 * the same release as the browser extension (tools/defuddle, compiled into one binary).
 *
 * Pages on private networks (localhost, 10.0.0.0/8, ...) are refused unless explicitly allowed:
 * the URL can come from an AI assistant over MCP, and the transcript goes back to it.
 */
final readonly class ArticleExtractor
{
    public const int TIMEOUT_SECONDS = 20;
    public const int MAX_BYTES = 8_000_000;
    public const int EXTRACTION_SECONDS = 30;

    private HttpClientInterface $httpClient;

    private string $defuddle;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(param: 'crieur.allow_private_networks')]
        bool $allowPrivateNetworks = false,
        #[Autowire(param: 'crieur.defuddle')]
        ?string $defuddle = null,
    ) {
        $this->httpClient = $allowPrivateNetworks ? $httpClient : new NoPrivateNetworkHttpClient($httpClient);
        $this->defuddle = $defuddle ?? \dirname(__DIR__, 2).'/tools/defuddle/build/defuddle';
    }

    /**
     * The version of Defuddle, or null when its binary is missing or does not run.
     */
    public function defuddleVersion(): ?string
    {
        if (!is_file($this->defuddle) || !is_executable($this->defuddle)) {
            return null;
        }
        $process = new Process([$this->defuddle, '--version']);
        $process->setTimeout(10);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    /**
     * @throws ExtractionFailed
     */
    public function extract(string $url): ExtractedArticle
    {
        [$finalUrl, $html] = $this->fetch($url);
        $article = $this->defuddle(self::withoutFurniture($html), $finalUrl);

        $content = \is_string($article['content'] ?? null) ? $article['content'] : '';
        if ('' === trim(strip_tags($content))) {
            throw new ExtractionFailed(ExtractionFailed::NO_TEXT);
        }
        $image = self::text($article, 'image');
        $siteName = self::text($article, 'site');
        $author = self::text($article, 'author');

        return new ExtractedArticle(
            url: $finalUrl,
            title: TitleCleaner::clean(self::text($article, 'title') ?? '', $siteName, $finalUrl),
            html: $content,
            siteName: $siteName,
            // Without a byline, Defuddle gives the name of the site as author.
            author: null !== $author && $author !== $siteName ? $author : null,
            excerpt: self::text($article, 'description'),
            imageUrl: null !== $image && preg_match('~^https?://~i', $image) ? $image : null,
            languageHint: self::text($article, 'language') ?? self::documentLanguage($html),
            publishedAt: self::date(self::text($article, 'published')),
        );
    }

    /**
     * @return array<string, mixed> the answer of Defuddle: title, content (HTML), site, author, ...
     */
    private function defuddle(string $html, string $url): array
    {
        if (!is_file($this->defuddle) || !is_executable($this->defuddle)) {
            throw new ExtractionFailed(ExtractionFailed::EXTRACTOR, \sprintf('%s is missing: run tools/defuddle/build.sh', $this->defuddle));
        }

        $process = new Process([$this->defuddle, $url]);
        $process->setInput($html);
        $process->setTimeout(self::EXTRACTION_SECONDS);
        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new ExtractionFailed(ExtractionFailed::TIMEOUT, $e->getMessage(), $e);
        }
        if (!$process->isSuccessful()) {
            throw new ExtractionFailed(ExtractionFailed::EXTRACTOR, trim($process->getErrorOutput()));
        }

        try {
            $article = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ExtractionFailed(ExtractionFailed::EXTRACTOR, $e->getMessage(), $e);
        }
        if (!\is_array($article) || isset($article['error'])) {
            throw new ExtractionFailed(ExtractionFailed::NO_TEXT);
        }

        /** @var array<string, mixed> $article */
        return $article;
    }

    /**
     * @param array<string, mixed> $article
     */
    private static function text(array $article, string $key): ?string
    {
        return \is_string($article[$key] ?? null) ? self::clean($article[$key]) : null;
    }

    /**
     * @return array{string, string} the final URL after redirects, and the HTML
     */
    private function fetch(string $url): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => self::TIMEOUT_SECONDS,
                'max_duration' => self::TIMEOUT_SECONDS,
                'max_redirects' => 5,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15 Crieur/1.0',
                    'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
                    'Accept-Language' => 'fr,en;q=0.8',
                ],
                'on_progress' => static function (int $downloaded): void {
                    if ($downloaded > self::MAX_BYTES) {
                        throw new TransportException('The page is too large.');
                    }
                },
            ]);

            $status = $response->getStatusCode();
            if ($status >= 400) {
                throw new ExtractionFailed(ExtractionFailed::HTTP_ERROR, \sprintf('HTTP %d', $status));
            }

            $contentType = strtolower($response->getHeaders()['content-type'][0] ?? 'text/html');
            if (!str_contains($contentType, 'html')) {
                throw new ExtractionFailed(ExtractionFailed::NOT_HTML, $contentType);
            }

            $finalUrl = $response->getInfo('url');

            return [\is_string($finalUrl) ? $finalUrl : $url, self::toUtf8($response->getContent(), $contentType)];
        } catch (TimeoutException $e) {
            throw new ExtractionFailed(ExtractionFailed::TIMEOUT, $e->getMessage(), $e);
        } catch (TransportException $e) {
            $reason = str_contains($e->getMessage(), '" is blocked for "')
                ? ExtractionFailed::PRIVATE_NETWORK
                : (str_contains(strtolower($e->getMessage()), 'timeout') || str_contains(strtolower($e->getMessage()), 'timed out') ? ExtractionFailed::TIMEOUT : ExtractionFailed::NETWORK);

            throw new ExtractionFailed($reason, $e->getMessage(), $e);
        } catch (ExceptionInterface $e) {
            throw new ExtractionFailed(ExtractionFailed::NETWORK, $e->getMessage(), $e);
        }
    }

    /**
     * Page furniture (hatnotes, infoboxes, edit links, reference marks) is removed before Defuddle
     * runs: some of it would come out as plain paragraphs, without the classes that tell what it is.
     */
    private static function withoutFurniture(string $html): string
    {
        $document = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR, 'UTF-8');
        $selector = implode(', ', [...array_map(static fn (string $class): string => '.'.$class, HtmlBlockExtractor::BOILERPLATE_CLASSES), '[role="note"]']);
        foreach ($document->querySelectorAll($selector) as $element) {
            if ($element->parentNode instanceof \Dom\Element && $element !== $document->body) {
                $element->parentNode->removeChild($element);
            }
        }

        return $document->saveHtml();
    }

    private static function toUtf8(string $html, string $contentType): string
    {
        $charset = preg_match('/charset=([\w-]+)/i', $contentType, $m) ? $m[1] : null;
        if (null === $charset && preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m)) {
            $charset = $m[1];
        }

        if (null === $charset || 'utf-8' === strtolower($charset) || 'utf8' === strtolower($charset)) {
            return mb_scrub($html, 'UTF-8');
        }

        try {
            $converted = @mb_convert_encoding($html, 'UTF-8', $charset);
        } catch (\ValueError) {
            // A charset mbstring does not know: the page is read as UTF-8.
            $converted = false;
        }

        return mb_scrub(false === $converted ? $html : $converted, 'UTF-8');
    }

    private static function documentLanguage(string $html): ?string
    {
        return preg_match('/<html[^>]*\slang=["\']?([a-zA-Z-]+)/i', substr($html, 0, 4096), $m) ? $m[1] : null;
    }

    private static function clean(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8')) ?? '');

        return '' === $value ? null : $value;
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === trim($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}

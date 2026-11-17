<?php

declare(strict_types=1);

namespace App\Extraction;

use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches a web page and extracts its article with readability.php, a port of Mozilla Readability.
 *
 * Pages on private networks (localhost, 10.0.0.0/8, ...) are refused unless explicitly allowed:
 * the URL can come from an AI assistant over MCP, and the transcript goes back to it.
 */
final readonly class ArticleExtractor
{
    public const int TIMEOUT_SECONDS = 20;
    public const int MAX_BYTES = 8_000_000;

    private HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(param: 'crieur.allow_private_networks')]
        bool $allowPrivateNetworks = false,
    ) {
        $this->httpClient = $allowPrivateNetworks ? $httpClient : new NoPrivateNetworkHttpClient($httpClient);
    }

    /**
     * @throws ExtractionFailed
     */
    public function extract(string $url): ExtractedArticle
    {
        [$finalUrl, $html] = $this->fetch($url);

        try {
            $article = (new Readability(fixRelativeURLs: true, originalURL: $finalUrl))->parse($html);
        } catch (ParseException $e) {
            throw new ExtractionFailed(ExtractionFailed::NO_TEXT, $e->getMessage(), $e);
        }

        $content = $article->content;
        if (!$article->hasContent() || null === $content) {
            throw new ExtractionFailed(ExtractionFailed::NO_TEXT);
        }

        return new ExtractedArticle(
            url: $finalUrl,
            title: self::clean($article->title) ?? '',
            html: $content,
            siteName: self::clean($article->siteName),
            author: self::clean($article->byline),
            excerpt: self::clean($article->excerpt),
            imageUrl: null !== $article->image && preg_match('~^https?://~i', $article->image) ? $article->image : null,
            languageHint: self::clean($article->lang) ?? self::documentLanguage($html),
            publishedAt: self::date($article->publishedTime),
        );
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

    private static function toUtf8(string $html, string $contentType): string
    {
        $charset = preg_match('/charset=([\w-]+)/i', $contentType, $m) ? $m[1] : null;
        if (null === $charset && preg_match('/<meta[^>]+charset=["\']?([\w-]+)/i', substr($html, 0, 4096), $m)) {
            $charset = $m[1];
        }

        if (null === $charset || 'utf-8' === strtolower($charset) || 'utf8' === strtolower($charset)) {
            return mb_scrub($html, 'UTF-8');
        }

        $converted = @mb_convert_encoding($html, 'UTF-8', $charset);

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

<?php

declare(strict_types=1);

namespace App\Tests\Extraction;

use App\Extraction\ArticleExtractor;
use App\Extraction\ExtractionFailed;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ArticleExtractorTest extends TestCase
{
    #[Test]
    public function itExtractsTheArticleAndItsMetadata(): void
    {
        $html = (string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html');
        $extractor = new ArticleExtractor(new MockHttpClient(new MockResponse($html, ['response_headers' => ['content-type' => 'text/html; charset=utf-8']])), allowPrivateNetworks: true);

        $article = $extractor->extract('https://example.org/essay');

        static::assertSame('Why we read slower than we listen', $article->title);
        static::assertSame('The Field Notes', $article->siteName);
        static::assertSame('Ada Lovecraft', $article->author);
        static::assertSame('en', $article->languageHint);
        static::assertSame('https://example.org/cover.jpg', $article->imageUrl);
        static::assertSame('2026-11-02', $article->publishedAt?->format('Y-m-d'));
        static::assertStringContainsString('The reason is not speed, it is attention.', $article->html);
        static::assertStringNotContainsString('Subscribe to our newsletter', $article->html);
        static::assertStringNotContainsString('All rights reserved', $article->html);
    }

    #[Test]
    public function itReadsAPageWithAnUnknownCharsetAsUtf8(): void
    {
        $html = str_replace('<head>', '<head><meta charset="x-unknown-charset">', (string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html'));
        $extractor = new ArticleExtractor(new MockHttpClient(new MockResponse($html, ['response_headers' => ['content-type' => 'text/html']])), allowPrivateNetworks: true);

        static::assertSame('Why we read slower than we listen', $extractor->extract('https://example.org/essay')->title);
    }

    #[Test]
    public function itReportsAnHttpError(): void
    {
        $extractor = new ArticleExtractor(new MockHttpClient(new MockResponse('Gone', ['http_code' => 404])), allowPrivateNetworks: true);

        $this->expectExceptionObject(new ExtractionFailed(ExtractionFailed::HTTP_ERROR, 'HTTP 404'));

        $extractor->extract('https://example.org/missing');
    }

    #[Test]
    public function itRefusesWhatIsNotAPage(): void
    {
        $extractor = new ArticleExtractor(new MockHttpClient(new MockResponse('%PDF-1.7', ['response_headers' => ['content-type' => 'application/pdf']])), allowPrivateNetworks: true);

        try {
            $extractor->extract('https://example.org/paper.pdf');
            static::fail('A PDF is not an article.');
        } catch (ExtractionFailed $e) {
            static::assertSame(ExtractionFailed::NOT_HTML, $e->reason);
        }
    }

    #[Test]
    public function itRefusesPrivateNetworksByDefault(): void
    {
        $extractor = new ArticleExtractor(new MockHttpClient(new MockResponse('<html><body><p>secret</p></body></html>')));

        try {
            $extractor->extract('http://127.0.0.1:8080/admin');
            static::fail('A private address must be refused.');
        } catch (ExtractionFailed $e) {
            static::assertSame(ExtractionFailed::PRIVATE_NETWORK, $e->reason);
        }
    }

    #[Test]
    public function itReportsAPageWithoutArticle(): void
    {
        $extractor = new ArticleExtractor(new MockHttpClient(new MockResponse('<html><head><title>Empty</title></head><body></body></html>', ['response_headers' => ['content-type' => 'text/html']])), allowPrivateNetworks: true);

        try {
            $extractor->extract('https://example.org/');
            static::fail('A page without text has no article.');
        } catch (ExtractionFailed $e) {
            static::assertSame(ExtractionFailed::NO_TEXT, $e->reason);
        }
    }

    #[Test]
    public function itLeavesThePageFurnitureOut(): void
    {
        $html = '<html lang="en"><head><title>Town crier</title></head><body><main><article>'
            .'<div role="note" class="hatnote">"Crier" redirects here.</div>'
            .'<table class="infobox"><tr><th>Occupation</th><td>Civil service</td></tr></table>'
            .'<h2>Duties<span class="mw-editsection">[edit]</span></h2>'
            .str_repeat('<p>Criers dressed elaborately, by a tradition dating to the 18th century.<sup class="reference">[w 1]</sup> They rang a handbell to attract attention before every announcement.</p>', 4)
            .'</article></main></body></html>';
        $extractor = new ArticleExtractor(new MockHttpClient(new MockResponse($html, ['response_headers' => ['content-type' => 'text/html']])), allowPrivateNetworks: true);

        $article = $extractor->extract('https://example.org/crier');

        static::assertStringNotContainsString('redirects here', $article->html);
        static::assertStringNotContainsString('Civil service', $article->html);
        static::assertStringNotContainsString('[edit]', $article->html);
        static::assertStringNotContainsString('[w 1]', $article->html);
        static::assertStringContainsString('They rang a handbell', $article->html);
    }

    #[Test]
    public function itSaysWhenTheExtractorIsMissing(): void
    {
        $html = (string) file_get_contents(__DIR__.'/../Fixtures/extraction/article.html');
        $extractor = new ArticleExtractor(new MockHttpClient(new MockResponse($html)), allowPrivateNetworks: true, defuddle: '/nonexistent/defuddle');

        try {
            $extractor->extract('https://example.org/essay');
            static::fail('A missing extractor must be reported.');
        } catch (ExtractionFailed $e) {
            static::assertSame(ExtractionFailed::EXTRACTOR, $e->reason);
        }
        static::assertNull($extractor->defuddleVersion());
        static::assertSame('0.19.6', (new ArticleExtractor(new MockHttpClient()))->defuddleVersion());
    }
}

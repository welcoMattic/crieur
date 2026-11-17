<?php

declare(strict_types=1);

namespace App\Tests\Library;

use App\Library\ArticleQueue;
use App\Library\InvalidArticleUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArticleQueueUrlTest extends TestCase
{
    #[Test]
    #[DataProvider('acceptedUrls')]
    public function itAcceptsWebPages(string $given, string $expected): void
    {
        static::assertSame($expected, ArticleQueue::normalizeUrl($given));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedUrls(): iterable
    {
        yield 'https' => ['https://symfony.com/blog/', 'https://symfony.com/blog/'];
        yield 'http with spaces around' => ['  http://example.org/a?b=c  ', 'http://example.org/a?b=c'];
        yield 'bare domain gets https' => ['symfony.com/blog', 'https://symfony.com/blog'];
    }

    #[Test]
    #[DataProvider('refusedUrls')]
    public function itRefusesWhatIsNotAWebPage(string $given, string $reason): void
    {
        $this->expectException(InvalidArticleUrl::class);
        $this->expectExceptionMessage($reason);

        ArticleQueue::normalizeUrl($given);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedUrls(): iterable
    {
        yield 'empty' => ['  ', 'empty'];
        yield 'file scheme' => ['file:///etc/passwd', 'scheme'];
        yield 'javascript' => ['javascript:alert(1)', 'scheme'];
        yield 'ftp' => ['ftp://example.org/file', 'scheme'];
        yield 'no host' => ['https://', 'host'];
        yield 'too long' => ['https://example.org/'.str_repeat('a', 2100), 'too_long'];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Article;
use App\Enum\ArticleSource;

final class ShareControllerTest extends ControllerTestCase
{
    public function testTheBookmarkletQueuesTheArticleAndOpensIt(): void
    {
        $this->client->request('GET', '/share?url='.urlencode('https://example.org/story'), server: [
            'HTTP_SEC_FETCH_MODE' => 'navigate',
            'HTTP_SEC_FETCH_DEST' => 'document',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);

        self::assertResponseStatusCodeSame(303);
        $articles = $this->entityManager()->getRepository(Article::class)->findAll();
        self::assertCount(1, $articles);
        self::assertSame(ArticleSource::Bookmarklet, $articles[0]->getSource());
        self::assertResponseRedirects(\sprintf('/articles/%s?play=1', $articles[0]->getId()));
    }

    public function testASubresourceOrAnUnknownClientGetsAConfirmationPage(): void
    {
        $this->client->request('GET', '/share?url='.urlencode('https://example.org/story'), server: [
            'HTTP_SEC_FETCH_MODE' => 'no-cors',
            'HTTP_SEC_FETCH_DEST' => 'image',
        ]);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');

        $this->client->request('GET', '/share?url='.urlencode('https://example.org/story'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Read this article aloud?');
        self::assertCount(0, $this->entityManager()->getRepository(Article::class)->findAll());

        $this->client->submit($this->client->getCrawler()->selectButton('Read aloud')->form(), serverParameters: self::sameOrigin());
        self::assertResponseStatusCodeSame(303);
        self::assertCount(1, $this->entityManager()->getRepository(Article::class)->findAll());
    }

    public function testTheConfirmationNeedsAFormOfThisSite(): void
    {
        $this->client->request('POST', '/share', ['url' => 'https://example.org/story', '_token' => 'csrf-token'], server: self::crossSite());

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager()->getRepository(Article::class)->findAll());
    }

    public function testAShareSheetMayPutTheLinkInTheText(): void
    {
        $this->client->request('GET', '/share?text='.urlencode('Worth a listen https://example.org/story?ref=share'), server: [
            'HTTP_SEC_FETCH_MODE' => 'navigate',
            'HTTP_SEC_FETCH_DEST' => 'document',
        ]);

        self::assertResponseStatusCodeSame(303);
        $articles = $this->entityManager()->getRepository(Article::class)->findAll();
        self::assertSame('https://example.org/story?ref=share', $articles[0]->getUrl());
    }
}

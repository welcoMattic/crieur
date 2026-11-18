<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Enum\ArticleSource;

final class HomeControllerTest extends ControllerTestCase
{
    public function testHomeExplainsTheThreeWaysWhenTheLibraryIsEmpty(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Paste a link');
        self::assertCount(1, $crawler->filter('form[name="article"] input[name="article[url]"]'));
        self::assertCount(3, $crawler->filter('.empty .way'));
        self::assertSelectorExists('#player[data-turbo-permanent]');
    }

    public function testHomeListsTheArticles(): void
    {
        $this->createArticle(source: ArticleSource::Mcp);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.entry'));
        self::assertSelectorTextContains('.entry__title', 'On listening');
        self::assertSelectorTextContains('.entry .badge', 'MCP');
        self::assertSelectorTextContains('.entry__status', 'Ready');
    }

    public function testTheLibraryFrameAnswersAloneAndWithoutItsOwnSource(): void
    {
        $this->createArticle(ready: 1);

        $this->client->request('GET', '/', server: ['HTTP_TURBO_FRAME' => 'library']);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('<turbo-frame id="library"', ltrim(preg_replace('/\{#.*?#\}/s', '', $content) ?? $content));
        self::assertStringNotContainsString('<html', $content);
        self::assertStringNotContainsString(' src=', explode('>', $content, 2)[0]);
        self::assertStringContainsString('Synthesizing 1/4', $content);
    }

    public function testTheInterfaceFollowsAcceptLanguageThenTheCookie(): void
    {
        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);
        self::assertSelectorTextContains('h1', 'Collez un lien');
        self::assertSelectorExists('html[lang="fr"]');

        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9']);
        self::assertSelectorTextContains('h1', 'Paste a link');

        $this->client->request('GET', '/locale/fr?return=/settings');
        self::assertResponseRedirects('/settings');
        self::assertResponseHasCookie('crieur_locale');

        $this->client->request('GET', '/', server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9']);
        self::assertSelectorTextContains('h1', 'Collez un lien');
    }

    public function testTheLocaleSwitchOnlyReturnsToThisSite(): void
    {
        $this->client->request('GET', '/locale/en?return=//evil.example/');

        self::assertResponseRedirects('/');
    }

    public function testTheHomePageShowsTheModelsInUse(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.compose .models', 'phonon:40m-multilingual');
        self::assertSelectorTextContains('.compose .models', 'Rules only');
        self::assertSelectorExists('.compose .models a[href="/settings#voice"]');
    }
}

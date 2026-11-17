<?php

declare(strict_types=1);

namespace App\Library;

use App\Entity\Article;
use App\Enum\ArticleSource;
use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use App\Message\ExtractArticle;
use App\Message\SynthesizeArticle;
use App\Repository\ArticleRepository;
use App\Speech\VoiceCatalog;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The single entry point to add an article or change how it is read, for the web interface,
 * the bookmarklet, the MCP server and the console alike.
 */
final readonly class ArticleQueue
{
    public const int MAX_URL_LENGTH = 2048;

    public function __construct(
        private ArticleRepository $articles,
        private MessageBusInterface $bus,
        private VoiceCatalog $voices,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws InvalidArticleUrl
     */
    public function queue(
        string $url,
        ArticleSource $source,
        LanguageSetting $language = LanguageSetting::Auto,
        VoiceGender $gender = VoiceGender::Female,
    ): Article {
        $article = new Article(self::normalizeUrl($url), $language, $gender, $this->voices->defaultEngine()->name, $source, $this->clock->now());
        $this->articles->save($article);
        $this->bus->dispatch(new ExtractArticle($article->getId()));

        return $article;
    }

    /**
     * Extracts and prepares the article again, for instance after a failure or with another language.
     */
    public function restart(Article $article, ?LanguageSetting $language = null): void
    {
        if (null !== $language) {
            $article->changeLanguageSetting($language, $this->clock->now());
            $this->articles->save($article);
        }

        $this->bus->dispatch(new ExtractArticle($article->getId()));
    }

    /**
     * Reads the rest of the article with another voice: the chunks are synthesized again.
     */
    public function changeVoice(Article $article, VoiceGender $gender): void
    {
        $language = $article->getLanguage();
        if (null === $language || ($gender === $article->getVoiceGender() && $article->getVoiceEngine() === $this->voices->defaultEngine()->name)) {
            return;
        }

        $voice = $this->voices->voice($this->voices->defaultEngine()->name, $language, $gender);
        $article->changeVoice($gender, $voice->name, $voice->engine, $this->clock->now());
        $this->articles->save($article);
        $this->bus->dispatch(new SynthesizeArticle($article->getId(), $article->getRevision()));
    }

    public function remove(Article $article): void
    {
        $this->articles->remove($article);
    }

    /**
     * @throws InvalidArticleUrl
     */
    public static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ('' === $url) {
            throw InvalidArticleUrl::because('empty');
        }

        if (\strlen($url) > self::MAX_URL_LENGTH) {
            throw InvalidArticleUrl::because('too_long');
        }

        if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) && preg_match('~^[\w.-]+\.[a-z]{2,}(/|$)~i', $url)) {
            $url = 'https://'.$url;
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            throw InvalidArticleUrl::because('scheme');
        }

        if ('' === ($parts['host'] ?? '')) {
            throw InvalidArticleUrl::because('host');
        }

        return $url;
    }
}

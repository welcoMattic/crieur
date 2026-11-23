<?php

declare(strict_types=1);

namespace App\Library;

use App\Demo\PublicDemo;
use App\Demo\TooManyArticles;
use App\Editor\SpeechEditor;
use App\Entity\Article;
use App\Enum\ArticleSource;
use App\Enum\ArticleStatus;
use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use App\Extraction\ExtractedArticle;
use App\Extraction\ExtractionFailed;
use App\Message\EditArticle;
use App\Message\ExtractArticle;
use App\Message\SynthesizeArticle;
use App\Repository\ArticleRepository;
use App\Speech\SpeechSynthesizer;
use App\Speech\VoiceCatalog;
use App\Text\NoReadableText;
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
        private SpeechEditor $editor,
        private ArticlePreparation $preparation,
        private SpeechSynthesizer $synthesizer,
        private PublicDemo $demo,
    ) {
    }

    /**
     * @throws InvalidArticleUrl
     * @throws TooManyArticles   on the public demo
     */
    public function queue(
        string $url,
        ArticleSource $source,
        LanguageSetting $language = LanguageSetting::Auto,
        VoiceGender $gender = VoiceGender::Female,
    ): Article {
        $url = self::normalizeUrl($url);
        $this->demo->consumeArticle();
        $article = new Article($url, $language, $gender, $this->voices->defaultEngine()->name, $source, $this->clock->now());
        $this->articles->save($article);
        $this->bus->dispatch(new ExtractArticle($article->getId()));

        return $article;
    }

    /**
     * Adds an article the browser extension extracted from the page the reader sees, with the
     * reader's own access: it is prepared at once, never fetched by the server.
     *
     * @throws InvalidArticleUrl
     * @throws TooManyArticles   on the public demo
     * @throws ExtractionFailed  for a language other than French or English, or too long for the public demo
     * @throws NoReadableText    under 30 words
     */
    public function queueFromBrowser(ExtractedArticle $page, LanguageSetting $language = LanguageSetting::Auto, VoiceGender $gender = VoiceGender::Female): Article
    {
        $url = self::normalizeUrl($page->url);
        $this->demo->consumeArticle();
        $article = new Article($url, $language, $gender, $this->voices->defaultEngine()->name, ArticleSource::Extension, $this->clock->now());
        $this->preparation->fromPage($article, $page);

        return $article;
    }

    /**
     * Prepares the article again, for instance after a failure or with another language. An
     * article read in the browser keeps its text: the server could not fetch its page.
     *
     * The page is fetched and read again, so on the public demo it counts as one more article:
     * a page that changes at each fetch would otherwise be synthesized again and again, beyond
     * the audio already made.
     *
     * @throws TooManyArticles on the public demo, before anything changes
     */
    public function restart(Article $article, ?LanguageSetting $language = null): void
    {
        if (ArticleSource::Extension !== $article->getSource()) {
            $this->demo->consumeArticle();
        }

        if (null !== $language) {
            $article->changeLanguageSetting($language, $this->clock->now());
            $this->articles->save($article);
        }

        if (ArticleSource::Extension !== $article->getSource()) {
            $this->bus->dispatch(new ExtractArticle($article->getId()));

            return;
        }

        try {
            $this->preparation->fromTranscript($article);
        } catch (ExtractionFailed $e) {
            $article->fail($e->reason, $this->clock->now());
            $this->articles->save($article);
        } catch (NoReadableText) {
            $article->fail(ExtractionFailed::NO_TEXT, $this->clock->now());
            $this->articles->save($article);
        }
    }

    /**
     * Reads the rest of the article with another voice: the chunks are synthesized again, starting
     * where the listener is.
     */
    public function changeVoice(Article $article, VoiceGender $gender, int $fromPosition = 0): void
    {
        $language = $article->getLanguage();
        if (null === $language || ($gender === $article->getVoiceGender() && $article->getVoiceEngine() === $this->voices->defaultEngine()->name)) {
            return;
        }

        $voice = $this->voices->voice($this->voices->defaultEngine()->name, $language, $gender);
        $article->changeVoice($gender, $voice->name, $voice->engine, $this->clock->now());
        $this->articles->save($article);
        $this->bus->dispatch(new SynthesizeArticle($article->getId(), $article->getRevision(), max(0, $fromPosition)));
        // The edits already made stay; the editor goes on with the chunks it had not reached.
        if (null !== $this->editor->backend()) {
            $this->bus->dispatch(new EditArticle($article->getId(), $article->getRevision()));
        }
    }

    /**
     * Takes chunks out of the reading, or puts them back. A removed chunk gets a short silence at
     * once, so nothing waits for it; a restored one is synthesized again.
     *
     * @param list<int> $positions
     */
    public function setRemoved(Article $article, array $positions, bool $removed): void
    {
        $language = $article->getLanguage();
        if (null === $language || [] === $positions) {
            return;
        }

        $voice = $this->voices->voice($article->getVoiceEngine(), $language, $article->getVoiceGender());
        $silence = $removed ? $this->synthesizer->silence(1, $voice) : null;
        $restoredFrom = null;
        foreach ($article->getSegments() as $segment) {
            if ($segment->isRemoved() === $removed || !\in_array($segment->getPosition(), $positions, true)) {
                continue;
            }
            if (null !== $silence) {
                $segment->remove($silence['key'], $silence['durationMs']);
            } else {
                $segment->restore();
                $restoredFrom = min($restoredFrom ?? $segment->getPosition(), $segment->getPosition());
            }
        }

        if (null !== $restoredFrom) {
            $article->resumeSynthesis($this->clock->now());
        } elseif (ArticleStatus::Synthesizing === $article->getStatus() && $article->countReadySegments() === $article->getSegments()->count()) {
            // The chunks left to synthesize were all removed.
            $article->markReady($this->clock->now());
        }
        $this->articles->save($article);

        if (null !== $restoredFrom) {
            $this->bus->dispatch(new SynthesizeArticle($article->getId(), $article->getRevision(), $restoredFrom));
        }
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
        if (false === $parts && preg_match('~^https?://~i', $url)) {
            throw InvalidArticleUrl::because('host');
        }

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

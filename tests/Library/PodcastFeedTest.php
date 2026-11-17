<?php

declare(strict_types=1);

namespace App\Tests\Library;

use App\Entity\Article;
use App\Enum\ArticleSource;
use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use App\Library\FeedToken;
use App\Speech\AudioStore;
use App\Speech\Wav;
use App\Tests\RecreatesSchema;
use App\Text\BlockKind;
use App\Text\Language;
use App\Text\PreparedArticle;
use App\Text\PreparedSegment;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class PodcastFeedTest extends WebTestCase
{
    use RecreatesSchema;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::recreateSchema(self::getContainer()->get(EntityManagerInterface::class));
    }

    #[Test]
    public function readyArticlesAreEpisodesOfThePrivateFeed(): void
    {
        $ready = $this->article('A ready article', ready: true);
        $this->article('Still synthesizing', ready: false);
        $token = self::getContainer()->get(FeedToken::class)->value();

        $this->client->request('GET', \sprintf('/feed/%s.xml', $token));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/rss+xml; charset=UTF-8');
        $feed = simplexml_load_string((string) $this->client->getResponse()->getContent());
        self::assertNotFalse($feed);
        self::assertCount(1, $feed->channel->item);
        self::assertSame('A ready article', (string) $feed->channel->item[0]->title);
        self::assertSame('http://www.itunes.com/dtds/podcast-1.0.dtd', ($feed->getDocNamespaces() ?: [])['itunes'] ?? null);

        $enclosure = $feed->channel->item[0]->enclosure;
        self::assertContains((string) $enclosure['type'], ['audio/mp4', 'audio/wav']);
        self::assertGreaterThan(0, (int) $enclosure['length']);
        self::assertStringContainsString($ready->getId()->toRfc4122(), (string) $enclosure['url']);

        $this->client->request('GET', (string) parse_url((string) $enclosure['url'], \PHP_URL_PATH));
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function theFeedNeedsItsSecret(): void
    {
        $this->client->request('GET', '/feed/not-the-token.xml');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function theEpisodeJoinsTheChunksAndTheirPauses(): void
    {
        $article = $this->article('Episode', ready: true);

        $this->client->request('GET', \sprintf('/articles/%s/episode', $article->getId()->toRfc4122()));

        self::assertResponseIsSuccessful();
        $file = $this->client->getResponse();
        self::assertInstanceOf(BinaryFileResponse::class, $file);
        $path = $file->getFile()->getPathname();
        if (str_ends_with($path, '.wav')) {
            // Two chunks of 500 ms, a pause of 250 ms after the first and of 700 ms after the last.
            self::assertSame(1950, Wav::fromBytes((string) file_get_contents($path))->durationMs());
        } else {
            self::assertStringEndsWith('.m4a', $path);
        }
    }

    private function article(string $title, bool $ready): Article
    {
        $article = new Article('https://example.org/'.md5($title), LanguageSetting::Auto, VoiceGender::Female, 'phonon', ArticleSource::Web);
        $article->describe($title, 'Example', null, null, null, null);
        $article->prepare(new PreparedArticle($title, Language::English, [
            new PreparedSegment(0, BlockKind::Title, $title, $title, 250),
            new PreparedSegment(1, BlockKind::Paragraph, 'One sentence.', 'One sentence.', 700),
        ], 2, 4), 'Hadley', 'rules');

        $store = self::getContainer()->get(AudioStore::class);
        foreach ($article->getSegments() as $segment) {
            if ($ready || 0 === $segment->getPosition()) {
                $key = hash('xxh128', $title.$segment->getPosition());
                $store->write($key, Wav::silence(500)->toBytes());
                $segment->attachAudio($key, 500);
            }
        }
        if ($ready) {
            $article->markReady();
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($article);
        $entityManager->flush();

        return $article;
    }
}

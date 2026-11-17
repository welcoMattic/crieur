<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Article;
use App\Enum\ArticleSource;
use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use App\Speech\AudioStore;
use App\Speech\Wav;
use App\Text\BlockKind;
use App\Text\Language;
use App\Text\PreparedArticle;
use App\Text\PreparedSegment;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A fresh schema in the test database (var/data_test.db) for each test, and helpers to add
 * articles with silent audio.
 */
abstract class ControllerTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $entityManager = $this->entityManager();
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    protected function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * An article read by Hadley: the title, then two paragraphs. The first $ready chunks have audio (all four by default).
     */
    protected function createArticle(int $ready = 4, ArticleSource $source = ArticleSource::Web): Article
    {
        $article = new Article('https://example.org/listening', LanguageSetting::Auto, VoiceGender::Female, 'phonon', $source);
        $article->startExtraction();
        $article->describe('On listening', 'Example Review', 'Ada Writer', null, null, null);
        $article->prepare(new PreparedArticle('On listening', Language::English, [
            new PreparedSegment(0, BlockKind::Title, 'On listening', 'On listening', 900),
            new PreparedSegment(1, BlockKind::Paragraph, 'It was 3.5 km away.', 'It was three and a half kilometres away.', 250),
            new PreparedSegment(1, BlockKind::Paragraph, 'We walked.', 'We walked.', 700),
            new PreparedSegment(2, BlockKind::Paragraph, 'The end.', 'The end.', 700),
        ], 3, 10), 'Hadley', 'ministral-3:8b');
        $article->edit('ministral-3:8b', 'A short walk, told in four sentences.');

        $store = static::getContainer()->get(AudioStore::class);
        $key = hash('xxh128', 'crieur-test-silence-200');
        if (!$store->has($key)) {
            $store->write($key, Wav::silence(200, 8000)->toBytes());
        }
        foreach ($article->getSegments() as $segment) {
            if ($segment->getPosition() < $ready) {
                $segment->attachAudio($key, 200);
            }
        }
        if ($ready >= $article->getSegments()->count()) {
            $article->markReady();
        }

        $this->entityManager()->persist($article);
        $this->entityManager()->flush();

        return $article;
    }

    protected function reload(Article $article): ?Article
    {
        $this->entityManager()->clear();

        return $this->entityManager()->find(Article::class, $article->getId());
    }

    /**
     * What a browser sends with a form of this site: the stateless CSRF token is checked against
     * the origin of the request.
     *
     * @return array<string, string>
     */
    protected static function sameOrigin(): array
    {
        return ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_ORIGIN' => 'http://localhost'];
    }

    /**
     * @return array<string, string>
     */
    protected static function crossSite(): array
    {
        return ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_ORIGIN' => 'https://evil.example'];
    }
}

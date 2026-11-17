<?php

declare(strict_types=1);

namespace App\Tests\Text;

use App\Text\ArticlePreparer;
use App\Text\Block;
use App\Text\BlockKind;
use App\Text\Chunker;
use App\Text\HtmlBlockExtractor;
use App\Text\Language;
use App\Text\NoReadableText;
use App\Text\PreparedArticle;
use App\Text\PreparedSegment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ported from ArticlePreparerTests.swift. The Swift app read normalized chunks; here `text` is the
 * text of the article and `spoken` its normalized form: the assertions on the words read move to
 * `spoken`, the limits stay on `text`.
 */
final class ArticlePreparerTest extends TestCase
{
    private ArticlePreparer $preparer;

    protected function setUp(): void
    {
        $this->preparer = new ArticlePreparer();
    }

    /**
     * @return iterable<string, array{string, list<Block>, Language}>
     */
    public static function fixtures(): iterable
    {
        $extractor = new HtmlBlockExtractor();
        yield 'French article' => [TextFixtures::FRENCH_TITLE, $extractor->extract(TextFixtures::frenchHtml()), Language::French];
        yield 'English article' => [TextFixtures::ENGLISH_TITLE, $extractor->extract(TextFixtures::englishHtml()), Language::English];
        yield 'French plain text' => [TextFixtures::FRENCH_PLAIN_TITLE, TextFixtures::frenchPlain(), Language::French];
    }

    #[Test]
    public function theFrenchArticleIsCleanedAndNormalized(): void
    {
        $prepared = $this->prepareFixture('French article');
        $text = self::join($prepared, 'text');
        $spoken = self::join($prepared, 'spoken');

        self::assertSame(Language::French, $prepared->language);
        foreach (['http', 'example', '@', '[', '](', '<', '>', '**', '```', 'print', 'ne doit pas être lu', '|', '---', 'Source', 'photo de l’agence'] as $removed) {
            self::assertStringNotContainsString($removed, $text);
            self::assertStringNotContainsString($removed, $spoken);
        }
        foreach (['€', '%', '2023'] as $written) {
            self::assertStringContainsString($written, $text);
            self::assertStringNotContainsString($written, $spoken);
        }
        foreach (['l’Agence européenne', 'trois virgule cinq pour cent', 'douze mille kilomètres',
            'un million cinq cent mille voyageurs', 'Monsieur Bernard', 'Madame Lefèvre', 'Docteur Martin',
            'quatre-vingt-neuf euros quatre-vingt-dix', 'vingt-et-unième siècle', 'premier congrès',
            'Un modèle économique fragile', 'douze virgule cinq pour cent', 'deuxième trimestre',
            'Paris-Berlin, trois fois par semaine', 'seconde moitié', 'Ligne, Fréquence', 'et cetera.',
            'le prix de l’énergie', 'Contact :', 'deux mille vingt-trois', 'Le Docteur Lefèvre est venu ensuite.'] as $kept) {
            self::assertStringContainsStringIgnoringCase($kept, $spoken);
        }
    }

    #[Test]
    public function theEnglishArticleIsCleanedAndNormalized(): void
    {
        $prepared = $this->prepareFixture('English article');
        $spoken = self::join($prepared, 'spoken');

        self::assertSame(Language::English, $prepared->language);
        foreach (['http', 'example.org', '@', 'hidden', '€', '%', '['] as $removed) {
            self::assertStringNotContainsString($removed, $spoken);
        }
        foreach (['three point five percent', 'twelve thousand kilometers', 'one million five hundred thousand',
            'Mister Smith', 'eighty-nine euros ninety', 'Doctor Jones met him in Saint Louis, for example',
            'Paris to Vienna, every day', 'the European Agency', 'twenty twenty-three'] as $kept) {
            self::assertStringContainsString($kept, $spoken);
        }
    }

    #[Test]
    public function integersStayAsDigitsWhenTheEngineReadsThem(): void
    {
        $prepared = $this->preparer->prepare(TextFixtures::FRENCH_TITLE, (new HtmlBlockExtractor())->extract(TextFixtures::frenchHtml()), Language::French, false);
        self::assertStringContainsString('publiée en 2023 par', self::join($prepared, 'spoken'));
        self::assertStringContainsString('douze mille kilomètres', self::join($prepared, 'spoken'));
    }

    #[Test]
    public function theTitleIsParagraphZeroAndTheAuthorIsNotRead(): void
    {
        $prepared = $this->preparer->prepare('Le 1er train de la Ve République', TextFixtures::frenchPlain(), Language::French);
        $first = $prepared->segments[0];
        self::assertSame('Le 1er train de la Ve République', $first->text);
        self::assertSame('Le premier train de la cinquième République', $first->spoken);
        self::assertSame([0, BlockKind::Title, 700], [$first->paragraph, $first->kind, $first->pauseAfterMs]);
        self::assertSame(1, $prepared->segments[1]->paragraph);
        self::assertSame(BlockKind::Paragraph, $prepared->segments[1]->kind);
        self::assertSame('Le 1er train de la Ve République', $prepared->title);
    }

    #[Test]
    public function aFirstHeadingEqualToTheTitleIsNotReadTwice(): void
    {
        $blocks = [new Block(BlockKind::Heading, 'Une nuit dans le train'), ...TextFixtures::frenchPlain()];
        $prepared = $this->preparer->prepare("  Une nuit dans\n le TRAIN !", $blocks, Language::French);
        self::assertCount(1, array_filter($prepared->segments, static fn (PreparedSegment $segment): bool => 0 === strcasecmp('Une nuit dans le train', rtrim($segment->text, ' !'))));
        self::assertSame('Une nuit dans le TRAIN !', $prepared->segments[0]->text);
        self::assertSame('Une nuit dans le TRAIN !', $prepared->title);

        $other = $this->preparer->prepare('Un autre titre', $blocks, Language::French);
        self::assertSame([BlockKind::Title, BlockKind::Heading], [$other->segments[0]->kind, $other->segments[1]->kind]);
    }

    #[Test]
    public function anEmptyTitleGivesNoTitleChunk(): void
    {
        $prepared = $this->preparer->prepare('  ', TextFixtures::frenchPlain(), Language::French);
        self::assertStringStartsWith('Le quai est presque vide', $prepared->segments[0]->text);
        self::assertSame(0, $prepared->segments[0]->paragraph);
        self::assertSame(BlockKind::Paragraph, $prepared->segments[0]->kind);
        self::assertSame('', $prepared->title);
    }

    #[Test]
    public function pausesAre700AfterAParagraph250AfterASentenceAnd0InsideASentence(): void
    {
        $blocks = [
            new Block(BlockKind::Heading, 'Un titre de section'),
            new Block(BlockKind::Paragraph, 'Première phrase du paragraphe. Deuxième phrase du paragraphe.'),
            new Block(BlockKind::Paragraph, TextFixtures::FRENCH_LONG_SENTENCE.'.'),
            new Block(BlockKind::ListItem, 'Un point de liste'),
            new Block(BlockKind::ListItem, 'Un autre point de liste'),
        ];
        $segments = $this->preparer->prepare('Titre', $blocks, Language::French)->segments;

        self::assertSame(['Titre', 700], [$segments[0]->text, $segments[0]->pauseAfterMs]);
        self::assertSame(['Un titre de section', 700, BlockKind::Heading], [$segments[1]->text, $segments[1]->pauseAfterMs, $segments[1]->kind]);
        self::assertSame(['Première phrase du paragraphe.', 250], [$segments[2]->text, $segments[2]->pauseAfterMs]);
        self::assertSame(['Deuxième phrase du paragraphe.', 700], [$segments[3]->text, $segments[3]->pauseAfterMs]);
        $long = self::paragraph($segments, 3);
        self::assertGreaterThanOrEqual(3, \count($long));
        foreach (\array_slice($long, 0, -1) as $segment) {
            self::assertSame(0, $segment->pauseAfterMs);
        }
        self::assertSame(700, $long[\count($long) - 1]->pauseAfterMs);
        // A list is one paragraph, as in the Swift app: 250 ms between the items, 700 ms after it.
        $list = self::paragraph($segments, 4);
        self::assertSame(['Un point de liste', 'Un autre point de liste'], array_map(static fn (PreparedSegment $segment): string => $segment->text, $list));
        self::assertSame([250, 700], array_map(static fn (PreparedSegment $segment): int => $segment->pauseAfterMs, $list));
        self::assertSame([BlockKind::ListItem, BlockKind::ListItem], array_map(static fn (PreparedSegment $segment): BlockKind => $segment->kind, $list));
        self::assertSame(700, $segments[\count($segments) - 1]->pauseAfterMs);
    }

    /**
     * @param list<Block> $blocks
     */
    #[Test]
    #[DataProvider('fixtures')]
    public function noChunkIsLongerThanTheLimitAndTheFirstChunksHave120CharactersOrLess(string $title, array $blocks, Language $language): void
    {
        $prepared = $this->preparer->prepare($title, $blocks, $language);
        foreach ($prepared->segments as $segment) {
            self::assertLessThanOrEqual($language->chunkLimit(), mb_strlen($segment->text));
        }
        self::assertLessThanOrEqual(Chunker::FIRST_LIMIT, mb_strlen($prepared->segments[0]->text));
        self::assertLessThanOrEqual(Chunker::FIRST_LIMIT, mb_strlen(self::paragraph($prepared->segments, 1)[0]->text));
    }

    #[Test]
    public function aFirstBodySentenceLongerThan120CharactersIsSplit(): void
    {
        $blocks = [new Block(BlockKind::Paragraph, TextFixtures::FRENCH_LONG_SENTENCE.'.'), new Block(BlockKind::Paragraph, TextFixtures::FRENCH_COMMA_SENTENCE)];
        $segments = $this->preparer->prepare('Titre', $blocks, Language::French)->segments;
        self::assertLessThanOrEqual(120, mb_strlen($segments[1]->text));
        self::assertSame(0, $segments[1]->pauseAfterMs);
        self::assertGreaterThan(120, mb_strlen($segments[2]->text));
    }

    #[Test]
    public function aTitleLongerThan120CharactersIsSplit(): void
    {
        $title = 'Le grand retour des trains de nuit en Europe, entre nostalgie des voyageurs, ambitions climatiques '
            .'des gouvernements et réalité économique des compagnies ferroviaires';
        $segments = $this->preparer->prepare($title, TextFixtures::frenchPlain(), Language::French)->segments;
        $titleSegments = self::paragraph($segments, 0);
        self::assertGreaterThanOrEqual(2, \count($titleSegments));
        self::assertLessThanOrEqual(120, mb_strlen($titleSegments[0]->text));
        self::assertSame($title, implode(' ', array_map(static fn (PreparedSegment $segment): string => $segment->text, $titleSegments)));
        self::assertSame(700, $titleSegments[\count($titleSegments) - 1]->pauseAfterMs);
        foreach ($titleSegments as $segment) {
            self::assertSame(BlockKind::Title, $segment->kind);
        }
    }

    /**
     * @param list<Block> $blocks
     */
    #[Test]
    #[DataProvider('fixtures')]
    public function togetherTheChunksContainAllTheWordsOfTheArticle(string $title, array $blocks, Language $language): void
    {
        $prepared = $this->preparer->prepare($title, $blocks, $language);
        if (BlockKind::Heading === $blocks[0]->kind && TextFixtures::words($blocks[0]->text) === TextFixtures::words($title)) {
            // A first heading that repeats the title is read once.
            $blocks = \array_slice($blocks, 1);
        }
        $expected = TextFixtures::words($title.' '.implode(' ', array_map(static fn (Block $block): string => $block->text, $blocks)));
        self::assertSame($expected, TextFixtures::words(self::join($prepared, 'text')));
    }

    #[Test]
    public function plainTextKeepsExactlyItsWordsInOrder(): void
    {
        $prepared = $this->preparer->prepare(TextFixtures::FRENCH_PLAIN_TITLE, TextFixtures::frenchPlain(), Language::French);
        $expected = TextFixtures::words(TextFixtures::FRENCH_PLAIN_TITLE.' '.implode(' ', TextFixtures::FRENCH_PLAIN));
        self::assertSame($expected, TextFixtures::words(self::join($prepared, 'text')));
        self::assertSame($expected, TextFixtures::words(self::join($prepared, 'spoken')));
    }

    #[Test]
    public function randomTextsKeepTheirLimitsAndTheirCharacters(): void
    {
        foreach (range(1, 40) as $seed) {
            foreach (Language::cases() as $language) {
                $title = 'Titre '.$seed;
                $blocks = TextFixtures::randomBlocks($language, $seed);
                $prepared = $this->preparer->prepare($title, $blocks, $language, false);
                $limit = $language->chunkLimit();
                foreach ($prepared->segments as $segment) {
                    self::assertNotSame('', $segment->text);
                    self::assertLessThanOrEqual($limit, mb_strlen($segment->text), "seed {$seed}");
                }
                self::assertLessThanOrEqual(120, mb_strlen($prepared->segments[0]->text));
                self::assertLessThanOrEqual(120, mb_strlen(self::paragraph($prepared->segments, 1)[0]->text));
                $source = $title.implode('', array_map(static fn (Block $block): string => $block->text, $blocks));
                self::assertSame(
                    preg_replace('/\s+/u', '', $source),
                    preg_replace('/\s+/u', '', implode('', array_map(static fn (PreparedSegment $segment): string => $segment->text, $prepared->segments))),
                    "seed {$seed}, {$language->value}",
                );
            }
        }
    }

    /**
     * @param list<Block> $blocks
     */
    #[Test]
    #[DataProvider('fixtures')]
    public function paragraphIndexesParagraphCountAndWordCountAreCoherent(string $title, array $blocks, Language $language): void
    {
        $prepared = $this->preparer->prepare($title, $blocks, $language);
        $paragraphs = array_map(static fn (PreparedSegment $segment): int => $segment->paragraph, $prepared->segments);
        self::assertSame(0, $paragraphs[0]);
        for ($i = 1, $count = \count($paragraphs); $i < $count; ++$i) {
            self::assertContains($paragraphs[$i] - $paragraphs[$i - 1], [0, 1]);
        }
        self::assertSame($paragraphs[\count($paragraphs) - 1] + 1, $prepared->paragraphCount);
        self::assertCount($prepared->wordCount, TextFixtures::words(self::join($prepared, 'text')));
        for ($p = 0; $p < $prepared->paragraphCount; ++$p) {
            $segments = self::paragraph($prepared->segments, $p);
            self::assertSame(700, $segments[\count($segments) - 1]->pauseAfterMs);
        }
    }

    #[Test]
    public function theFrenchArticleHasTheExpectedParagraphs(): void
    {
        // Title, paragraph, heading, paragraph, HTML paragraph, "Les lignes principales :", list, table,
        // quote, long sentence, comma sentence.
        self::assertSame(11, $this->prepareFixture('French article')->paragraphCount);
    }

    #[Test]
    public function fewerThan30WordsGivesNoReadableText(): void
    {
        $twentyNine = [new Block(BlockKind::Paragraph, implode(' ', array_fill(0, 29, 'mot')))];
        $thirty = [new Block(BlockKind::Paragraph, implode(' ', array_fill(0, 30, 'mot')))];
        try {
            $this->preparer->prepare('Un titre de dix mots pour voir si on compte', $twentyNine, Language::French);
            self::fail('No exception for 29 words.');
        } catch (NoReadableText $e) {
            self::assertSame(29, $e->wordCount);
        }
        self::assertGreaterThan(1, \count($this->preparer->prepare('Titre', $thirty, Language::French)->segments));
    }

    #[Test]
    public function removedItemsDoNotCountAsWords(): void
    {
        $html = '<p><img src="a.png" alt="image"> https://example.org/a https://example.org/b contact@example.org</p>'
            .'<pre><code>'.implode(' ', array_fill(0, 50, 'code')).'</code></pre><p>Trois mots seulement.</p>';
        $this->expectException(NoReadableText::class);
        $this->preparer->prepare('Titre', (new HtmlBlockExtractor())->extract($html), Language::French);
    }

    #[Test]
    public function anEmptyTextGivesNoReadableText(): void
    {
        $this->expectException(NoReadableText::class);
        $this->preparer->prepare('', [], Language::English);
    }

    #[Test]
    public function chunksWithoutALetterOrADigitAreSkipped(): void
    {
        $blocks = [...TextFixtures::frenchPlain(), new Block(BlockKind::Paragraph, '* * * …'), new Block(BlockKind::Quote, '« ? »')];
        $prepared = $this->preparer->prepare('Titre', $blocks, Language::French);
        foreach ($prepared->segments as $segment) {
            self::assertMatchesRegularExpression('/[\p{L}\p{N}]/u', $segment->text);
        }
        self::assertSame(5, $prepared->paragraphCount);
    }

    private function prepareFixture(string $name): PreparedArticle
    {
        foreach (self::fixtures() as $key => [$title, $blocks, $language]) {
            if ($key === $name) {
                return $this->preparer->prepare($title, $blocks, $language);
            }
        }

        throw new \LogicException(\sprintf('No fixture "%s".', $name));
    }

    /**
     * @param 'text'|'spoken' $field
     */
    private static function join(PreparedArticle $prepared, string $field): string
    {
        return implode(' ', array_map(static fn (PreparedSegment $segment): string => $segment->{$field}, $prepared->segments));
    }

    /**
     * @param list<PreparedSegment> $segments
     *
     * @return list<PreparedSegment>
     */
    private static function paragraph(array $segments, int $paragraph): array
    {
        return array_values(array_filter($segments, static fn (PreparedSegment $segment): bool => $paragraph === $segment->paragraph));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Text;

use App\Text\ArticlePreparer;
use App\Text\BlockKind;
use App\Text\Chunker;
use App\Text\Language;
use App\Text\PreparedSegment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChunkerTest extends TestCase
{
    #[Test]
    public function limitsAre300InFrench400InEnglishAnd120ForTheFirstChunk(): void
    {
        self::assertSame(300, Language::French->chunkLimit());
        self::assertSame(400, Language::English->chunkLimit());
        self::assertSame(120, Chunker::FIRST_LIMIT);
    }

    #[Test]
    public function aLongSentenceIsSplitAtSemicolonColonOrCommaFirst(): void
    {
        $start = str_repeat('mot ', 30).'fin; ';
        $parts = Chunker::parts($start.str_repeat('suite ', 20).'fin.', 150, 150);
        self::assertSame(rtrim($start), $parts[0]);
        self::assertAllShorterThan(150, $parts);

        $colon = Chunker::parts('Il a dit : '.str_repeat('blabla ', 30), 100, 100);
        self::assertSame('Il a dit :', $colon[0]);

        $commas = Chunker::parts(TextFixtures::FRENCH_COMMA_SENTENCE, 120, 300);
        foreach (\array_slice($commas, 0, -1) as $part) {
            self::assertStringEndsWith(',', $part);
        }
        self::assertLessThanOrEqual(120, mb_strlen($commas[0]));
    }

    #[Test]
    public function aMarkInsideANumberOrATimeIsNotASplitPoint(): void
    {
        $parts = Chunker::parts(str_repeat('abc ', 20).'10:30,5;7 '.str_repeat('def ', 20), 100, 100);
        foreach ($parts as $part) {
            self::assertDoesNotMatchRegularExpression('/[:,;]$/', $part);
        }
    }

    #[Test]
    public function withoutAMarkTheSplitIsAtASpace(): void
    {
        $parts = Chunker::parts(TextFixtures::FRENCH_LONG_SENTENCE, 300, 300);
        self::assertGreaterThanOrEqual(3, \count($parts));
        self::assertAllShorterThan(300, $parts);
        self::assertSame(TextFixtures::FRENCH_LONG_SENTENCE, implode(' ', $parts));
    }

    #[Test]
    public function aWordLongerThanTheLimitGetsAHardCut(): void
    {
        $word = str_repeat('x', 700);
        $parts = Chunker::parts('Avant '.$word.' après', 120, 300);
        self::assertAllShorterThan(300, $parts);
        self::assertLessThanOrEqual(120, mb_strlen($parts[0]));
        self::assertSame('Avant'.$word.'après', str_replace(' ', '', implode('', $parts)));
    }

    #[Test]
    public function lengthsCountCharactersNotBytes(): void
    {
        $sentence = str_repeat('été ', 29);
        $parts = Chunker::parts($sentence, 120, 300);
        self::assertSame([rtrim($sentence)], $parts);
    }

    #[Test]
    public function numbersAmountsAndAbbreviationsAreNotCut(): void
    {
        $head = str_repeat('a', 95);
        self::assertSame([$head, '12 000 000 kilomètres'], Chunker::parts($head.' 12 000 000 kilomètres', 100, 300));
        self::assertSame([$head.' abc', "12,5\u{A0}% de hausse"], Chunker::parts($head." abc 12,5\u{A0}% de hausse", 105, 300));
        self::assertSame([$head.' abc', '89,90 € le billet'], Chunker::parts($head.' abc 89,90 € le billet', 106, 300));
        self::assertSame([$head.' et', 'M. Bernard arrive'], Chunker::parts($head.' et M. Bernard arrive', 101, 300));
    }

    #[Test]
    public function aShortSentenceStaysWholeAndTrimmed(): void
    {
        self::assertSame(['Il pleut.'], Chunker::parts("  Il pleut.\u{A0}", 120, 300));
        self::assertSame([], Chunker::parts('   ', 120, 300));
    }

    #[Test]
    public function splitCutsInTwoNearTheMiddleAtAMarkIfPossible(): void
    {
        $text = 'Le train part ce soir à vingt heures, il arrive demain matin à Vienne après une longue nuit';
        $halves = ArticlePreparer::split(new PreparedSegment(2, BlockKind::Paragraph, $text, $text, 700));
        self::assertCount(2, $halves);
        self::assertSame('Le train part ce soir à vingt heures,', $halves[0]->text);
        self::assertSame('il arrive demain matin à Vienne après une longue nuit', $halves[1]->text);
        self::assertSame([2, 2], [$halves[0]->paragraph, $halves[1]->paragraph]);
        self::assertSame([0, 700], [$halves[0]->pauseAfterMs, $halves[1]->pauseAfterMs]);
        self::assertSame([BlockKind::Paragraph, BlockKind::Paragraph], [$halves[0]->kind, $halves[1]->kind]);
    }

    #[Test]
    public function withoutAMarkNearTheMiddleSplitCutsAtTheSpaceNearestTheMiddle(): void
    {
        $text = TextFixtures::FRENCH_LONG_SENTENCE;
        $halves = ArticlePreparer::split(new PreparedSegment(0, BlockKind::Paragraph, $text, $text, 250));
        self::assertCount(2, $halves);
        self::assertSame($text, $halves[0]->text.' '.$halves[1]->text);
        self::assertLessThan(20, abs(mb_strlen($halves[0]->text) - mb_strlen($halves[1]->text)));
    }

    #[Test]
    public function splitHalvesTheDisplayTextAndTheSpokenTextEachOnItsOwn(): void
    {
        $segment = new PreparedSegment(1, BlockKind::Quote, 'Le 1er train part, il arrive à Vienne', 'Le premier train part, il arrive à Vienne', 250);
        $halves = ArticlePreparer::split($segment);
        self::assertSame(['Le 1er train part,', 'il arrive à Vienne'], [$halves[0]->text, $halves[1]->text]);
        self::assertSame(['Le premier train part,', 'il arrive à Vienne'], [$halves[0]->spoken, $halves[1]->spoken]);
    }

    #[Test]
    public function aChunkWithOneWordCannotBeSplit(): void
    {
        $segment = new PreparedSegment(0, BlockKind::Paragraph, 'anticonstitutionnellement', 'anticonstitutionnellement', 250);
        self::assertSame([$segment], ArticlePreparer::split($segment));
        self::assertNull(Chunker::halves('x'));
    }

    /**
     * @param list<string> $parts
     */
    private static function assertAllShorterThan(int $limit, array $parts): void
    {
        foreach ($parts as $part) {
            self::assertLessThanOrEqual($limit, mb_strlen($part), $part);
        }
    }
}

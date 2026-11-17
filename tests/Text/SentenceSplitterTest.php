<?php

declare(strict_types=1);

namespace App\Tests\Text;

use App\Text\Language;
use App\Text\SentenceSplitter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SentenceSplitterTest extends TestCase
{
    private SentenceSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new SentenceSplitter();
    }

    #[Test]
    public function theBreakIteratorSplitsTheSentences(): void
    {
        self::assertSame(
            ['Il pleut.', 'Il fait froid !', 'Pourquoi ?', 'Parce que.'],
            $this->splitter->split('Il pleut. Il fait froid ! Pourquoi ? Parce que.', Language::French),
        );
        self::assertSame(
            ['It rains.', 'Why?', 'Because it does!'],
            $this->splitter->split('It rains. Why? Because it does!', Language::English),
        );
    }

    #[Test]
    public function noSplitAfterAnAbbreviationOrAnInitial(): void
    {
        self::assertSame(
            ['Le Dr. Lefèvre est venu.', 'M. Dupont aussi.', 'Il est reparti.'],
            $this->splitter->split('Le Dr. Lefèvre est venu. M. Dupont aussi. Il est reparti.', Language::French),
        );
        self::assertSame(
            ['Voir cf. Pr. Truc pour la suite.', 'Fin.'],
            $this->splitter->split('Voir cf. Pr. Truc pour la suite. Fin.', Language::French),
        );
        self::assertSame(
            ['Un livre de J. K. Rowling est sorti.', 'Fin.'],
            $this->splitter->split('Un livre de J. K. Rowling est sorti. Fin.', Language::French),
        );
        self::assertSame(
            ['Mr. Smith met Dr. Jones in St. Louis.', 'They left.'],
            $this->splitter->split('Mr. Smith met Dr. Jones in St. Louis. They left.', Language::English),
        );
        self::assertSame(
            ['Le sujet (cf. Pr. Martin) est clos.', 'Fin.'],
            $this->splitter->split('Le sujet (cf. Pr. Martin) est clos. Fin.', Language::French),
        );
    }

    #[Test]
    public function theSentencesTogetherGiveBackTheWholeText(): void
    {
        foreach ([...TextFixtures::FRENCH_PLAIN, TextFixtures::FRENCH_LONG_SENTENCE, TextFixtures::FRENCH_COMMA_SENTENCE] as $text) {
            $flat = implode(' ', preg_split('/\s+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
            self::assertSame($flat, implode(' ', $this->splitter->split($flat, Language::French)));
        }
    }

    #[Test]
    public function closingQuotesStayWithTheirSentence(): void
    {
        self::assertSame(
            ['Été là.', '« Où ça ? »', 'Ici.'],
            $this->splitter->split('Été là. « Où ça ? » Ici.', Language::French),
        );
        self::assertSame(
            ['« Un jardin, ce n’est pas seulement des légumes.', 'C’est un endroit où l’on apprend la patience. »', 'Fin.'],
            $this->splitter->split('« Un jardin, ce n’est pas seulement des légumes. C’est un endroit où l’on apprend la patience. » Fin.', Language::French),
        );
    }

    #[Test]
    public function anEmptyTextHasNoSentence(): void
    {
        self::assertSame([], $this->splitter->split('', Language::French));
        self::assertSame([], $this->splitter->split('   ', Language::English));
    }
}

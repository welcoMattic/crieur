<?php

declare(strict_types=1);

namespace App\Tests\Text;

use App\Text\Language;
use App\Text\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Ported from TextNormalizerTests.swift (step 1, R-TX-5) and SpeechTextTests.swift (step 2, R-TS-16).
 */
final class TextNormalizerTest extends TestCase
{
    private TextNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new TextNormalizer();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function french(): iterable
    {
        // Decimal numbers.
        yield '3,5' => ['3,5', 'trois virgule cinq'];
        yield '3,05 mètres' => ['Il mesure 3,05 mètres.', 'Il mesure trois virgule zéro cinq mètres.'];
        yield '0,75' => ['0,75', 'zéro virgule soixante-quinze'];
        yield '12,125' => ['12,125', 'douze virgule cent vingt-cinq'];
        yield '3,14159' => ['3,14159', 'trois virgule un quatre un cinq neuf'];
        // Thousands separators: a space, U+00A0, U+202F, dots.
        yield '12 000' => ['12 000 kilomètres', 'douze mille kilomètres'];
        yield '12 000 with U+00A0' => ["12\u{00A0}000 kilomètres", 'douze mille kilomètres'];
        yield '12 000 with U+202F' => ["12\u{202F}000 kilomètres", 'douze mille kilomètres'];
        yield '1 500 000' => ['1 500 000 voyageurs', 'un million cinq cent mille voyageurs'];
        yield '1 000,5' => ['1 000,5', 'mille virgule cinq'];
        yield '1.000.000' => ['1.000.000 de personnes', 'un million de personnes'];
        yield '150 000 after a year' => ['En 2020, 150 000 personnes.', 'En 2020, cent cinquante mille personnes.'];
        // Percent.
        yield '3,5 %' => ['3,5 %', 'trois virgule cinq pour cent'];
        yield '50%' => ['50%', 'cinquante pour cent'];
        yield '50 % with U+202F' => ["50\u{202F}%", 'cinquante pour cent'];
        yield '12,5 % with U+00A0' => ["12,5\u{00A0}% de hausse", 'douze virgule cinq pour cent de hausse'];
        yield 'lone %' => ['le % restant', 'le pour cent restant'];
        // Euro.
        yield '89,90 €' => ['89,90 €', 'quatre-vingt-neuf euros quatre-vingt-dix'];
        yield '1 €' => ['1 €', 'un euro'];
        yield '1,50 €' => ['1,50 €', 'un euro cinquante'];
        yield '2,5 € with U+202F' => ["2,5\u{202F}€", 'deux euros cinquante'];
        yield '1 500 €' => ['1 500 €', 'mille cinq cents euros'];
        yield '10,00 €' => ['10,00 €', 'dix euros'];
        yield '2 M€' => ['2 M€', 'deux millions d’euros'];
        yield '1,5 Md€' => ['1,5 Md€', 'un virgule cinq milliard d’euros'];
        yield '30 k€' => ['30 k€', 'trente mille euros'];
        yield 'lone €' => ['en €', 'en euros'];
        // Ordinals.
        yield '1er' => ['le 1er janvier', 'le premier janvier'];
        yield '1re' => ['la 1re fois', 'la première fois'];
        yield '1ère' => ['la 1ère fois', 'la première fois'];
        yield '2e' => ['le 2e trimestre', 'le deuxième trimestre'];
        yield '2ème' => ['le 2ème', 'le deuxième'];
        yield '2nde' => ['la 2nde moitié', 'la seconde moitié'];
        yield '5e' => ['le 5e', 'le cinquième'];
        yield '9e' => ['le 9e', 'le neuvième'];
        yield '21e' => ['le 21e', 'le vingt-et-unième'];
        yield '80e' => ['le 80e', 'le quatre-vingtième'];
        yield '100e' => ['le 100e', 'le centième'];
        yield '1ers' => ['les 1ers arrivés', 'les premiers arrivés'];
        yield '3es' => ['les 3es places', 'les troisièmes places'];
        yield 'XIXe' => ['le XIXe siècle', 'le dix-neuvième siècle'];
        yield 'XXIe' => ['le XXIe siècle', 'le vingt-et-unième siècle'];
        yield 'Ier' => ['François Ier', 'François premier'];
        yield 'Ve' => ['la Ve République', 'la cinquième République'];
        yield 'IIIe' => ['le IIIe Reich', 'le troisième Reich'];
        yield '1ᵉʳ' => ['le 1ᵉʳ mai', 'le premier mai'];
        yield 'XIXᵉ' => ['le XIXᵉ siècle', 'le dix-neuvième siècle'];
        // Words that look like ordinals.
        yield 'Le chat' => ['Le chat dort.', 'Le chat dort.'];
        yield 'Ce matin' => ['Ce matin.', 'Ce matin.'];
        yield 'De plus' => ['De plus.', 'De plus.'];
        yield 'Louis XIV' => ['Louis XIV régnait.', 'Louis XIV régnait.'];
        yield 'CIC' => ['Le CIC paie.', 'Le CIC paie.'];
        yield 'Vie' => ['Une Vie heureuse.', 'Une Vie heureuse.'];
        // Plain integers, versions, times and dates stay unchanged.
        yield 'plain integers' => ['En 2024, 42 personnes.', 'En 2024, 42 personnes.'];
        yield 'time and date' => ['À 10:30, le 12/03/2024.', 'À 10:30, le 12/03/2024.'];
        // Abbreviations.
        yield 'M.' => ['M. Dupont arrive.', 'Monsieur Dupont arrive.'];
        yield 'MM.' => ['MM. Dupont et Durand', 'Messieurs Dupont et Durand'];
        yield 'Mme, Mlle' => ['Mme Martin et Mlle Roy', 'Madame Martin et Mademoiselle Roy'];
        yield 'Mmes' => ['Mmes Martin et Roy', 'Mesdames Martin et Roy'];
        yield 'Dr' => ['le Dr Martin et le Dr. Roy', 'le Docteur Martin et le Docteur Roy'];
        yield 'Pr' => ['le Pr Lebrun', 'le Professeur Lebrun'];
        yield 'Me' => ['Me Durand plaide.', 'Maître Durand plaide.'];
        yield 'St, Ste' => ['à St-Étienne et Ste Anne', 'à Saint-Étienne et Sainte Anne'];
        yield 'etc. at a sentence end' => ['des pommes, etc. Ensuite', 'des pommes, et cetera. Ensuite'];
        yield 'etc. inside' => ['des pommes, etc., puis', 'des pommes, et cetera, puis'];
        yield 'n°' => ['le n° 5', 'le numéro 5'];
        yield 'p.' => ['voir p. 12', 'voir page 12'];
        yield 'av. J.-C.' => ['en 52 av. J.-C. Puis', 'en 52 avant Jésus-Christ. Puis'];
        yield 'c.-à-d.' => ['c.-à-d. ici', 'c’est-à-dire ici'];
        yield 'M. after a number' => ['Le 5 M. de dollars', 'Le 5 M. de dollars'];
    }

    #[Test]
    #[DataProvider('french')]
    public function frenchSymbolsAreWrittenOut(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->writeOutSymbols($input, Language::French));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function english(): iterable
    {
        yield '3.5' => ['3.5', 'three point five'];
        yield '3.25 dollars' => ['It costs 3.25 dollars.', 'It costs three point two five dollars.'];
        yield '0.05' => ['0.05', 'zero point zero five'];
        yield '1,200' => ['1,200 people', 'one thousand two hundred people'];
        yield '1,000,000.5' => ['1,000,000.5', 'one million point five'];
        yield '12 000 with U+202F' => ["12\u{202F}000 km", 'twelve thousand km'];
        yield '3.5%' => ['grew by 3.5% in one year', 'grew by three point five percent in one year'];
        yield '100 %' => ['100 %', 'one hundred percent'];
        yield '€89.90' => ['€89.90', 'eighty-nine euros ninety'];
        yield '€1' => ['€1', 'one euro'];
        yield '15 €' => ['15 €', 'fifteen euros'];
        yield '€5bn' => ['€5bn', 'five billion euros'];
        yield '€5 million' => ['€5 million', 'five million euros'];
        yield 'plain integers and versions' => ['In 2024, version 3.5.1 shipped.', 'In 2024, version 3.5.1 shipped.'];
        yield 'codes' => ['The A320 and COVID-19.', 'The A320 and COVID-19.'];
        yield 'Mr., Mrs.' => ['Mr. Smith met Mrs. Smith.', 'Mister Smith met Missus Smith.'];
        yield 'Dr., St.' => ['Dr. Jones in St. Louis', 'Doctor Jones in Saint Louis'];
        yield 'Prof.' => ['Prof. Lee', 'Professor Lee'];
        yield 'e.g.' => ['fruit, e.g. apples', 'fruit, for example apples'];
        yield 'i.e.' => ['one, i.e. this', 'one, that is this'];
        yield 'etc.' => ['apples, etc. Then', 'apples, et cetera. Then'];
        yield 'vs.' => ['Smith vs. Jones', 'Smith versus Jones'];
        yield 'No., Fig.' => ['No. 5 and Fig. 2', 'number 5 and figure 2'];
        yield 'Jr.' => ['Martin Luther King Jr. spoke.', 'Martin Luther King Junior spoke.'];
    }

    #[Test]
    #[DataProvider('english')]
    public function englishSymbolsAreWrittenOut(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->writeOutSymbols($input, Language::English));
    }

    /**
     * The words of num2words, which the former Python engine used.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function integers(): iterable
    {
        yield '0' => ['0', 'zéro', 'zero'];
        yield '1' => ['1', 'un', 'one'];
        yield '7' => ['7', 'sept', 'seven'];
        yield '21' => ['21', 'vingt et un', 'twenty-one'];
        yield '71' => ['71', 'soixante et onze', 'seventy-one'];
        yield '80' => ['80', 'quatre-vingts', 'eighty'];
        yield '81' => ['81', 'quatre-vingt-un', 'eighty-one'];
        yield '91' => ['91', 'quatre-vingt-onze', 'ninety-one'];
        yield '100' => ['100', 'cent', 'one hundred'];
        yield '101' => ['101', 'cent un', 'one hundred one'];
        yield '200' => ['200', 'deux cents', 'two hundred'];
        yield '999' => ['999', 'neuf cent quatre-vingt-dix-neuf', 'nine hundred ninety-nine'];
        yield '1000' => ['1000', 'mille', 'one thousand'];
        yield '1001' => ['1001', 'mille un', 'one thousand one'];
        yield '1100' => ['1100', 'mille cent', 'eleven hundred'];
        yield '1984' => ['1984', 'mille neuf cent quatre-vingt-quatre', 'nineteen eighty-four'];
        yield '1900' => ['1900', 'mille neuf cents', 'nineteen hundred'];
        yield '1905' => ['1905', 'mille neuf cent cinq', 'nineteen oh-five'];
        yield '2000' => ['2000', 'deux mille', 'two thousand'];
        yield '2005' => ['2005', 'deux mille cinq', 'two thousand five'];
        yield '2010' => ['2010', 'deux mille dix', 'twenty ten'];
        yield '2024' => ['2024', 'deux mille vingt-quatre', 'twenty twenty-four'];
        yield '2099' => ['2099', 'deux mille quatre-vingt-dix-neuf', 'twenty ninety-nine'];
        yield '2100' => ['2100', 'deux mille cent', 'two thousand one hundred'];
        yield '12345' => ['12345', 'douze mille trois cent quarante-cinq', 'twelve thousand three hundred forty-five'];
        yield '1000000' => ['1000000', 'un million', 'one million'];
        yield '007' => ['007', '007', '007'];
        yield '16 digits' => ['1234567890123456', '1234567890123456', '1234567890123456'];
    }

    #[Test]
    #[DataProvider('integers')]
    public function plainIntegersAreSpelledForTheVoice(string $digits, string $french, string $english): void
    {
        self::assertSame($french, $this->normalizer->prepareForSpeech($digits, Language::French));
        self::assertSame($english, $this->normalizer->prepareForSpeech($digits, Language::English));
    }

    #[Test]
    public function onlyPlainIntegersAreSpelled(): void
    {
        self::assertSame(
            'En deux mille vingt-quatre, trois chats et 12 345 souris.',
            $this->normalizer->prepareForSpeech('En 2024, 3 chats et 12 345 souris.', Language::French),
        );
        $untouched = 'Le score est 3-2 et 10:30, A4, 5th, 3.5, 3,5, COVID-19, 42 %, 2024-05-12, $42, n°7, 42%, #1, @2, +3, 1/2.';
        self::assertSame($untouched, $this->normalizer->prepareForSpeech($untouched, Language::French));
        self::assertSame('The total was two hundred fifty.', $this->normalizer->prepareForSpeech('The total was 250.', Language::English));
        self::assertSame(
            'Chapter seven: nineteen eighty-four (and nineteen ninety-nine).',
            $this->normalizer->prepareForSpeech('Chapter 7: 1984 (and 1999).', Language::English),
        );
        self::assertSame('Il a 3 chats.', $this->normalizer->prepareForSpeech('Il a 3 chats.', Language::French, false));
    }

    #[Test]
    public function frenchMonsieurBeforeALowerCaseWord(): void
    {
        self::assertSame(
            'Monsieur le maire et Messieurs les adjoints, M. Dupont.',
            $this->normalizer->prepareForSpeech('M. le maire et MM. les adjoints, M. Dupont.', Language::French),
        );
        self::assertSame('M. le maire.', $this->normalizer->prepareForSpeech('M. le maire.', Language::English));
    }

    #[Test]
    public function whitespaceIsCollapsed(): void
    {
        self::assertSame('Une phrase courte.', TextNormalizer::collapseWhitespace("  Une\n\tphrase \u{00A0} courte.\u{202F} "));
    }

    /**
     * @return iterable<string, array{string, Language, bool, string}>
     */
    public static function bothSteps(): iterable
    {
        yield 'ordinals' => ['Le 1er train de la Ve République', Language::French, true, 'Le premier train de la cinquième République'];
        yield 'thousands then year' => ['En 2020, 150 000 personnes.', Language::French, true, 'En deux mille vingt, cent cinquante mille personnes.'];
        yield 'integers kept' => ['En 2020, 150 000 personnes.', Language::French, false, 'En 2020, cent cinquante mille personnes.'];
        yield 'no-break spaces' => ["Une hausse de 12,5\u{A0}% sur 12\u{202F}000\u{A0}km.", Language::French, true, 'Une hausse de douze virgule cinq pour cent sur douze mille km.'];
        yield 'Monsieur both ways' => ['M. Dupont et M. le maire', Language::French, true, 'Monsieur Dupont et Monsieur le maire'];
        yield 'idempotent on words' => ['deux mille vingt-quatre euros', Language::French, true, 'deux mille vingt-quatre euros'];
        yield 'English year and money' => ['In 1984, it cost €89.90.', Language::English, true, 'In nineteen eighty-four, it cost eighty-nine euros ninety.'];
        yield 'English with integers kept' => ['Chapter 7 of 12', Language::English, false, 'Chapter 7 of 12'];
    }

    #[Test]
    #[DataProvider('bothSteps')]
    public function normalizeChainsBothSteps(string $input, Language $language, bool $spellIntegers, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($input, $language, $spellIntegers));
    }

    #[Test]
    public function dollarsPoundsAndEnglishOrdinalsAreWrittenInWords(): void
    {
        $normalizer = new TextNormalizer();

        self::assertSame(
            'one thousand two hundred dollars in the first quarter, two point five billion pounds in the twenty-second.',
            $normalizer->writeOutSymbols('$1,200 in the 1st quarter, £2.5bn in the 22nd.', Language::English),
        );
        self::assertSame('trois millions de dollars et douze livres', $normalizer->writeOutSymbols('3 M$ et 12 £', Language::French));
    }
}

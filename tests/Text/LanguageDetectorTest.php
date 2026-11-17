<?php

declare(strict_types=1);

namespace App\Tests\Text;

use App\Text\Block;
use App\Text\HtmlBlockExtractor;
use App\Text\Language;
use App\Text\LanguageDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LanguageDetectorTest extends TestCase
{
    private LanguageDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new LanguageDetector();
    }

    #[Test]
    public function frenchAndEnglishAreDetectedAgainstTheHint(): void
    {
        self::assertSame(Language::French, $this->detector->detect(implode(' ', TextFixtures::FRENCH_PLAIN), 'en'));
        self::assertSame(Language::English, $this->detector->detect(TextFixtures::ENGLISH_LONG_SENTENCE, 'fr'));
    }

    /**
     * @return iterable<string, array{string, ?string, Language}>
     */
    public static function pages(): iterable
    {
        yield 'French page' => ['article-fr.html', 'fr', Language::French];
        yield 'English page' => ['article-en.html', 'en', Language::English];
        yield 'no lang attribute' => ['no-lang.html', null, Language::English];
        yield 'wrong lang attribute' => ['wrong-lang.html', 'en', Language::French];
    }

    #[Test]
    #[DataProvider('pages')]
    public function theFixturePagesAreDetected(string $page, ?string $hint, Language $expected): void
    {
        $blocks = (new HtmlBlockExtractor())->extract(TextFixtures::page($page));
        $text = implode("\n", array_map(static fn (Block $block): string => $block->text, $blocks));
        self::assertSame($expected, $this->detector->detect($text, $hint));
        self::assertGreaterThanOrEqual(LanguageDetector::MINIMUM_CONFIDENCE, $this->detector->guess($text)['confidence'] ?? 0.0);
    }

    #[Test]
    public function onlyTheFirst2000CharactersCount(): void
    {
        $french = str_repeat(TextFixtures::FRENCH_PLAIN[0].' ', 20);
        self::assertGreaterThan(LanguageDetector::SAMPLE_LENGTH, mb_strlen($french));
        $text = mb_substr($french, 0, LanguageDetector::SAMPLE_LENGTH).' '.str_repeat(TextFixtures::ENGLISH_LONG_SENTENCE.' ', 10);
        self::assertSame(Language::French, $this->detector->detect($text, 'en'));
    }

    /**
     * @return iterable<string, array{string, Language}>
     */
    public static function hints(): iterable
    {
        yield 'fr-FR' => ['fr-FR', Language::French];
        yield 'FR' => ['FR', Language::French];
        yield 'fr' => ['fr', Language::French];
        yield 'en_US' => ['en_US', Language::English];
        yield 'en-GB' => ['en-GB', Language::English];
        yield 'spaces' => [' en ', Language::English];
    }

    #[Test]
    #[DataProvider('hints')]
    public function aTextTooShortToDecideUsesTheFirstTwoLettersOfTheHint(string $hint, Language $expected): void
    {
        self::assertNull($this->detector->guess('x'));
        self::assertSame($expected, $this->detector->detect('x', $hint));
        self::assertSame($expected, $this->detector->detect('', $hint));
        self::assertSame($expected, $this->detector->detect('Une nuit dans le train', $hint));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherLanguages(): iterable
    {
        yield 'Spanish' => ['El jardín de la ciudad es muy bonito y los niños juegan en el parque con sus amigos todos los días después de la escuela.'];
        yield 'Italian' => ['Il treno di notte è tornato in Europa e molti viaggiatori scelgono questo mezzo per attraversare il continente con calma.'];
        yield 'German' => ['Der Nachtzug ist wieder in Europa unterwegs, und viele Reisende wählen ihn, um den Kontinent in Ruhe zu durchqueren.'];
        yield 'Portuguese' => ['O comboio noturno voltou à Europa e muitos viajantes escolhem este meio para atravessar o continente com calma. Durante a noite, as pessoas dormem em pequenas camas enquanto a paisagem passa pela janela.'];
        yield 'Dutch' => ['De nachttrein is terug in Europa en veel reizigers kiezen hem om het continent rustig te doorkruisen. Tijdens de nacht slapen ze in een smalle couchette terwijl het landschap voorbij het raam trekt.'];
    }

    #[Test]
    #[DataProvider('otherLanguages')]
    public function aConfidentOtherLanguageIsUnsupportedWhateverTheHint(string $text): void
    {
        self::assertNull($this->detector->detect($text, 'fr'));
        self::assertNull($this->detector->detect($text));
    }

    #[Test]
    public function noSupportedLanguageGivesNull(): void
    {
        self::assertNull($this->detector->detect('x', 'de'));
        self::assertNull($this->detector->detect('x', ''));
        self::assertNull($this->detector->detect('x'));
        self::assertNull($this->detector->detect('1234 5678 9012'));
    }

    #[Test]
    public function fromCodeReadsTheFirstTwoLetters(): void
    {
        self::assertSame(Language::French, LanguageDetector::fromCode('fr_CA'));
        self::assertSame(Language::English, LanguageDetector::fromCode('EN'));
        self::assertNull(LanguageDetector::fromCode('es'));
        self::assertNull(LanguageDetector::fromCode('f'));
    }
}

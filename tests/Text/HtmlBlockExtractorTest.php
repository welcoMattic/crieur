<?php

declare(strict_types=1);

namespace App\Tests\Text;

use App\Text\Block;
use App\Text\BlockKind;
use App\Text\HtmlBlockExtractor;
use fivefilters\Readability\Readability;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HtmlBlockExtractorTest extends TestCase
{
    private HtmlBlockExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new HtmlBlockExtractor();
    }

    #[Test]
    public function theFrenchArticleGivesItsHeadingsParagraphsListQuoteAndTable(): void
    {
        $blocks = $this->extractor->extract(TextFixtures::frenchHtml());

        self::assertEquals([
            new Block(BlockKind::Heading, 'Le retour des trains de nuit en Europe'),
            new Block(BlockKind::Paragraph, 'Après des années de déclin, les trains de nuit reviennent en Europe. Selon une étude publiée en 2023 par l’Agence européenne, la fréquentation a augmenté de 3,5 % en un an. Le réseau compte désormais 12 000 kilomètres de lignes et transporte 1 500 000 voyageurs par an.'),
            new Block(BlockKind::Heading, 'Un modèle économique fragile'),
            new Block(BlockKind::Paragraph, 'M. Bernard, directeur de la Compagnie, explique que le billet moyen coûte 89,90 € et que la rentabilité reste faible. Mme Lefèvre, économiste, rappelle que le Dr Martin avait prédit ce retour au XXIe siècle, lors du 1er congrès européen du rail. Le Dr. Lefèvre est venu ensuite. Contact : ou.'),
            new Block(BlockKind::Paragraph, "Le prix de l’énergie a augmenté de 12,5\u{A0}% depuis le 2e trimestre."),
            new Block(BlockKind::Paragraph, 'Les lignes principales :'),
            new Block(BlockKind::ListItem, 'Paris-Berlin, trois fois par semaine'),
            new Block(BlockKind::ListItem, 'Paris-Vienne, tous les jours'),
            new Block(BlockKind::ListItem, 'Bruxelles-Prague, à partir de la 2nde moitié de l’année'),
            new Block(BlockKind::Paragraph, 'Ligne, Fréquence. Paris-Berlin, trois par semaine.'),
            new Block(BlockKind::Quote, 'Le train de nuit, c’est la lenteur choisie, etc. Il faut le défendre.'),
            new Block(BlockKind::Paragraph, TextFixtures::FRENCH_LONG_SENTENCE.'.'),
            new Block(BlockKind::Paragraph, TextFixtures::FRENCH_COMMA_SENTENCE),
        ], $blocks);
    }

    #[Test]
    public function theEnglishArticleDropsCodeUrlsEmailsAndNotes(): void
    {
        $text = implode("\n", array_map(static fn (Block $block): string => $block->text, $this->extractor->extract(TextFixtures::englishHtml())));

        foreach (['http', 'example.org', '@', 'hidden', '[', 'Source'] as $removed) {
            self::assertStringNotContainsString($removed, $text);
        }
        self::assertStringContainsString('the European Agency, ridership grew by 3.5% in one year.', $text);
        self::assertStringContainsString('Write to or visit for details.', $text);
    }

    #[Test]
    public function inlineTextStaysInOrderAndWhitespaceIsCollapsed(): void
    {
        $blocks = $this->extractor->extract("<p>Un <em>texte <strong>très</strong>\n\t important</em>, <a href=\"/a\"><span>lu</span> en</a> ordre&nbsp;!<br>Fin.</p>");
        self::assertEquals([new Block(BlockKind::Paragraph, "Un texte très important, lu en ordre\u{A0}! Fin.")], $blocks);
    }

    #[Test]
    public function headingsOfEveryLevelAreHeadings(): void
    {
        $blocks = $this->extractor->extract('<h1>Un</h1><h3>Trois</h3><h6>Six <small>petit</small></h6>');
        self::assertEquals([
            new Block(BlockKind::Heading, 'Un'),
            new Block(BlockKind::Heading, 'Trois'),
            new Block(BlockKind::Heading, 'Six petit'),
        ], $blocks);
    }

    #[Test]
    public function mediaFiguresScriptsAndCodeBlocksAreDropped(): void
    {
        $blocks = $this->extractor->extract('<p>Avant <img src="a.png" alt="une image"> après.</p>'
            .'<figure><img src="b.png"><figcaption>Une légende</figcaption></figure>'
            .'<figure><blockquote>Une citation dans une figure.</blockquote></figure>'
            .'<script>alert("non")</script><style>p { color: red }</style><noscript>Activez JavaScript</noscript>'
            .'<pre><code>let x = 1</code></pre><p>Le code <code>x</code> reste.</p>'
            .'<video><source src="v.mp4">Vidéo</video><svg><text>Texte SVG</text></svg>'
            .'<iframe src="https://example.org"></iframe><p hidden>Caché</p><p aria-hidden="true">Masqué</p>');
        self::assertEquals([
            new Block(BlockKind::Paragraph, 'Avant après.'),
            new Block(BlockKind::Quote, 'Une citation dans une figure.'),
            new Block(BlockKind::Paragraph, 'Le code x reste.'),
        ], $blocks);
    }

    #[Test]
    public function footnoteMarksAreDropped(): void
    {
        $blocks = $this->extractor->extract('<p>Un fait<sup>1</sup>, un autre<sup><a href="#fn2">[2]</a></sup>, '
            .'un troisième<a href="#note-3">3</a> et un quatrième [4]. Le XIX<sup>e</sup> siècle et le 1<sup>er</sup> mai restent.</p>'
            .'<p>Wikipédia<sup class="reference"><a href="#cite_note-5"><span>[</span>5<span>]</span></a></sup> aussi.</p>');
        self::assertEquals([
            new Block(BlockKind::Paragraph, 'Un fait, un autre, un troisième et un quatrième. Le XIXe siècle et le 1er mai restent.'),
            new Block(BlockKind::Paragraph, 'Wikipédia aussi.'),
        ], $blocks);
    }

    #[Test]
    public function urlsAndEmailsAreDroppedAndLinksKeepTheirText(): void
    {
        $blocks = $this->extractor->extract('<p>Lisez <a href="https://example.org/a">notre enquête</a>, '
            .'<a href="https://example.org/b">https://example.org/b</a> ou www.example.org/c (https://example.org/d), '
            .'écrivez à <a href="mailto:a@example.org">a@example.org</a> ou mailto:b@example.org.</p>');
        self::assertEquals([new Block(BlockKind::Paragraph, 'Lisez notre enquête, ou, écrivez à ou.')], $blocks);
    }

    #[Test]
    public function listItemsAndNestedListsGiveOneBlockEach(): void
    {
        $blocks = $this->extractor->extract('<ul><li>Premier</li><li><p>Deuxième, en paragraphe</p></li>'
            .'<li>Troisième<ol><li>Sous-point un</li><li>Sous-point deux</li></ol>suite du troisième</li></ul>');
        self::assertEquals([
            new Block(BlockKind::ListItem, 'Premier'),
            new Block(BlockKind::ListItem, 'Deuxième, en paragraphe'),
            new Block(BlockKind::ListItem, 'Troisième'),
            new Block(BlockKind::ListItem, 'Sous-point un'),
            new Block(BlockKind::ListItem, 'Sous-point deux'),
            new Block(BlockKind::ListItem, 'suite du troisième'),
        ], $blocks);
    }

    #[Test]
    public function quoteParagraphsAreQuotesOnce(): void
    {
        $blocks = $this->extractor->extract('<blockquote><p>Première phrase citée.</p><p>Seconde phrase citée.</p>'
            .'<footer>Victor Hugo</footer></blockquote><blockquote>Citation sans paragraphe.</blockquote>');
        self::assertEquals([
            new Block(BlockKind::Quote, 'Première phrase citée.'),
            new Block(BlockKind::Quote, 'Seconde phrase citée.'),
            new Block(BlockKind::Quote, 'Victor Hugo'),
            new Block(BlockKind::Quote, 'Citation sans paragraphe.'),
        ], $blocks);
    }

    #[Test]
    public function bareTextInAContainerIsAParagraph(): void
    {
        $blocks = $this->extractor->extract('<div>Texte libre <b>avant</b><p>Un paragraphe.</p>texte libre après</div>');
        self::assertEquals([
            new Block(BlockKind::Paragraph, 'Texte libre avant'),
            new Block(BlockKind::Paragraph, 'Un paragraphe.'),
            new Block(BlockKind::Paragraph, 'texte libre après'),
        ], $blocks);
    }

    #[Test]
    public function aTableIsOneParagraphWithOneSentencePerRow(): void
    {
        $blocks = $this->extractor->extract('<table><caption>Les horaires</caption><tr><th>Ville</th><th>Départ</th></tr>'
            .'<tr><td>Paris</td><td><p>20 h 30</p></td></tr><tr><td>Lyon !</td><td></td></tr><tr><td> </td></tr></table>');
        self::assertEquals([new Block(BlockKind::Paragraph, 'Ville, Départ. Paris, 20 h 30. Lyon !')], $blocks);
    }

    #[Test]
    public function entitiesAreDecodedAndBlocksWithoutWordsAreDropped(): void
    {
        $blocks = $this->extractor->extract('<p>&laquo;&#8239;Caf&eacute;&#8239;&raquo; &amp; th&#xE9;</p><p>* * *</p><hr><p> </p>');
        self::assertEquals([new Block(BlockKind::Paragraph, "«\u{202F}Café\u{202F}» & thé")], $blocks);
        self::assertSame([], $this->extractor->extract(''));
        self::assertSame([], $this->extractor->extract('<img src="a.png">'));
    }

    /**
     * The cases of MarkdownCleanerTests.swift, written as the HTML readability returns.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function markdownCleanerCases(): iterable
    {
        yield 'link keeps its text' => ['<p>Lire <a href="https://ex.com/a_(b)" title="titre">cet article</a> ce soir.</p>', 'Lire cet article ce soir.'];
        yield 'link with emphasis' => ['<p>Un <a href="https://ex.com">lien <strong>gras</strong></a> ici.</p>', 'Un lien gras ici.'];
        yield 'image removed' => ['<p>Avant <img src="https://ex.com/p.jpg" alt="une photo"> après.</p>', 'Avant après.'];
        yield 'image inside a link' => ['<p><a href="https://ex.com"><img src="l.png" alt="logo"></a> Texte.</p>', 'Texte.'];
        yield 'image alone' => ['<p><img src="x.png" alt="seule image"></p>', ''];
        yield 'footnotes' => ['<p>Une phrase<sup id="fnref1"><a href="#fn1">1</a></sup> et une autre<sup><a href="#fn2">2</a></sup>.</p>'
            .'<div class="footnotes"><ol><li id="fn1"><p>La note un. Suite de la note.</p></li><li id="fn2">La note deux.</li></ol></div>', 'Une phrase et une autre.'];
        yield 'tags' => ['<p>Le <strong>prix</strong> a <em>baissé</em>.<br/>Enfin.</p>', 'Le prix a baissé. Enfin.'];
        yield 'comment' => ['<p>Texte <!-- commentaire --> visible.</p>', 'Texte visible.'];
        yield 'script and style' => ['<p>Avant <script>alert(\'x\')</script><style>p { }</style> après.</p>', 'Avant après.'];
        yield 'entities' => ['<p>L&rsquo;&eacute;t&eacute; &amp; l&#39;hiver&#x21;</p>', 'L’été & l\'hiver!'];
        yield 'escaped markup' => ['<p>Tom &amp; Jerry &lt;3</p>', 'Tom & Jerry <3'];
        yield 'code blocks' => ['<p>Avant le code.</p><pre><code class="language-swift">let secret = "ne pas lire"</code></pre>'
            .'<pre>autre code</pre><blockquote><pre><code>code cité</code></pre></blockquote>'
            .'<p>Après le code avec <code>NSString</code> dedans.</p>', 'Avant le code. | Après le code avec NSString dedans.'];
        yield 'bare URL' => ['<p>Voir https://example.org/a?b=1. Ensuite, la suite.</p>', 'Voir. Ensuite, la suite.'];
        yield 'bare e-mails' => ['<p>Écrivez à presse@example.org ou à <a href="mailto:contact@example.fr">contact@example.fr</a>.</p>', 'Écrivez à ou à.'];
        yield 'www and URL links' => ['<p>Site : www.example.com, et <a href="https://ex.com/x">https://ex.com/x</a>, fin.</p>', 'Site :, et, fin.'];
        yield 'URL in parentheses' => ['<p>Le lien (https://ex.com) est mort.</p>', 'Le lien est mort.'];
        yield 'mailto link' => ['<p>Envoyez <a href="mailto:a@b.fr">un courriel</a> vite.</p>', 'Envoyez un courriel vite.'];
        yield 'emphasis' => ['<p>Du <strong>gras</strong>, de l’<em>italique</em>, du <b>gras</b>, du <u>souligné</u> et <del>barré</del>.</p>',
            'Du gras, de l’italique, du gras, du souligné et barré.'];
        yield 'literal symbols' => ['<p>Un *astérisque* et un snake_case_nom.</p>', 'Un *astérisque* et un snake_case_nom.'];
        yield 'lists' => ['<p>Introduction.</p><ul><li>Premier point</li><li>Deuxième point
            sur deux lignes</li><li><input type="checkbox" checked> Tâche faite</li></ul>'
            .'<ol><li>Étape un</li><li>Étape deux</li></ol><p>Conclusion.</p>',
            'Introduction. | Premier point | Deuxième point sur deux lignes | Tâche faite | Étape un | Étape deux | Conclusion.'];
        yield 'table' => ['<table><thead><tr><th>Langue</th><th>Femme</th><th>Homme</th></tr></thead><tbody>'
            .'<tr><td>Anglais</td><td>af_heart</td><td><img src="y.png" alt="x"></td></tr>'
            .'<tr><td>Français</td><td><strong>ff_siwis</strong></td><td>em_alex</td></tr></tbody></table><p>Après.</p>',
            'Langue, Femme, Homme. Anglais, af_heart. Français, ff_siwis, em_alex. | Après.'];
    }

    #[Test]
    #[DataProvider('markdownCleanerCases')]
    public function theCleaningRulesOfTheSwiftAppApply(string $html, string $expected): void
    {
        self::assertSame($expected, implode(' | ', array_map(static fn (Block $block): string => $block->text, $this->extractor->extract($html))));
    }

    #[Test]
    public function headingsAndParagraphsStayEachInItsOwnBlock(): void
    {
        $blocks = $this->extractor->extract("<h1>Titre principal</h1><p>Premier paragraphe\nsur deux lignes.</p><h2>Sous-titre</h2>"
            .'<blockquote><p>Une citation.</p></blockquote><hr><p>Dernier paragraphe.</p>');
        self::assertEquals([
            new Block(BlockKind::Heading, 'Titre principal'),
            new Block(BlockKind::Paragraph, 'Premier paragraphe sur deux lignes.'),
            new Block(BlockKind::Heading, 'Sous-titre'),
            new Block(BlockKind::Quote, 'Une citation.'),
            new Block(BlockKind::Paragraph, 'Dernier paragraphe.'),
        ], $blocks);
    }

    #[Test]
    public function readabilityOutputOfTheFixturePages(): void
    {
        $french = $this->extractor->extract(self::readability('article-fr.html'));
        self::assertSame(
            'À Lyon, les jardins partagés se multiplient. En cinq ans, leur nombre a presque doublé. Reportage dans trois parcelles où les habitants cultivent ensemble des légumes, des fleurs et, surtout, des liens.',
            $french[1]->text,
        );
        self::assertSame(
            ['Un mouvement qui s’accélère', 'Des règles simples et un compost commun', 'Un lieu d’apprentissage pour les enfants'],
            array_values(array_map(static fn (Block $block): string => $block->text, array_filter($french, static fn (Block $block): bool => BlockKind::Heading === $block->kind))),
        );
        self::assertStringEndsWith('« Un jardin, ce n’est pas seulement des légumes. C’est un endroit où l’on apprend la patience, ensemble. »', $french[\count($french) - 1]->text);
        foreach ($french as $block) {
            self::assertStringNotContainsString('accueille quarante-trois adhérents', $block->text);
        }

        $english = $this->extractor->extract(self::readability('article-en.html'));
        self::assertSame(BlockKind::Heading, $english[4]->kind);
        self::assertSame('Hot water holds less oxygen', $english[4]->text);
        foreach ($english as $block) {
            self::assertStringNotContainsString('Cape Perpetua', $block->text);
        }
    }

    #[Test]
    public function pageFurnitureKeptByReadabilityIsNotRead(): void
    {
        $blocks = $this->extractor->extract(<<<'HTML'
            <div class="hatnote" role="note">"Crier" redirects here. For the surname, see Crier (surname).</div>
            <table class="infobox"><tr><th>Occupation</th><td>Civil service</td></tr></table>
            <h2>History<span class="mw-editsection">[edit]</span></h2>
            <p>Criers dressed elaborately.<sup class="reference"><a href="#cite_note-w1">[w 1]</a></sup> They rang a bell.</p>
            <span class="sr-only">Skip to content</span>
            HTML);

        self::assertEquals([
            new Block(BlockKind::Heading, 'History'),
            new Block(BlockKind::Paragraph, 'Criers dressed elaborately. They rang a bell.'),
        ], $blocks);
    }

    private static function readability(string $page): string
    {
        $content = (new Readability(fixRelativeURLs: true, originalURL: 'https://example.org/a'))->parse(TextFixtures::page($page))->content;
        self::assertNotNull($content);

        return $content;
    }
}

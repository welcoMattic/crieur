<?php

declare(strict_types=1);

namespace App\Tests\Text;

use App\Text\Block;
use App\Text\BlockKind;
use App\Text\Language;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Test texts of the Text tests, ported from TextFixtures.swift. The Markdown articles of the
 * Swift app are written here as the HTML that readability returns.
 */
final class TextFixtures
{
    public const string FRENCH_LONG_SENTENCE = 'Les voyageurs qui choisissent le train de nuit pour traverser le continent racontent souvent la même '
        .'expérience celle d’un voyage lent qui permet de dormir dans une couchette étroite pendant que les paysages '
        .'défilent derrière la fenêtre et de se réveiller au petit matin dans une ville inconnue où les boulangeries '
        .'ouvrent à peine et où les rues encore vides semblent attendre les premiers passants qui sortent de la gare '
        .'avec leurs valises et leurs cafés tièdes achetés à la hâte sur le quai pendant que les contrôleurs saluent '
        .'les habitués et que les enfants regardent avec étonnement les grandes locomotives qui reprennent déjà la '
        .'route vers une autre capitale lointaine où d’autres voyageurs attendent eux aussi leur tour pour partir';

    public const string FRENCH_COMMA_SENTENCE = 'Dans les couloirs, entre deux voitures, on croise des étudiants, des familles, des retraités, des cadres '
        .'pressés, des musiciens avec leurs instruments, des cyclistes qui ont rangé leur vélo, des randonneurs, des '
        .'couples en voyage de noces, des chercheurs qui relisent leurs notes, des enfants en pyjama, des contrôleurs '
        .'fatigués, des serveurs du wagon-restaurant, des photographes, des écrivains en quête d’inspiration, et même '
        .'parfois un ministre qui préfère le rail à l’avion, par conviction ou par prudence, selon les jours.';

    public const string ENGLISH_LONG_SENTENCE = 'Travelers who choose the night train to cross the continent often describe the same experience of a slow '
        .'journey that lets them sleep in a narrow berth while the landscape rolls past the window and then wake up '
        .'early in an unknown city where the bakeries are just opening and the empty streets seem to wait for the first '
        .'passengers who leave the station with their suitcases and their lukewarm coffee bought in a hurry on the '
        .'platform while the conductors greet the regulars and the children stare at the huge locomotives that are '
        .'already heading toward another distant capital where other travelers are waiting for their turn to leave';

    /**
     * No markup, no digit, no abbreviation: the chunks must contain exactly these words.
     */
    public const string FRENCH_PLAIN_TITLE = 'Une nuit dans le train';

    public const array FRENCH_PLAIN = [
        'Le quai est presque vide quand le train arrive en gare. Les voyageurs montent sans se presser, cherchent '
        .'leur compartiment et posent leurs sacs sur les couchettes. Un contrôleur passe dans le couloir et vérifie '
        .'les billets avec un sourire fatigué.',
        self::FRENCH_LONG_SENTENCE,
        self::FRENCH_COMMA_SENTENCE,
        'Au matin, le train ralentit enfin. La lumière entre par la fenêtre et les premiers voyageurs descendent '
        .'sur le quai, encore endormis, heureux d’être arrivés.',
    ];

    public const string FRENCH_TITLE = 'Le retour des trains de nuit en Europe';

    public const string ENGLISH_TITLE = 'The return of night trains in Europe';

    /**
     * @return list<Block>
     */
    public static function frenchPlain(): array
    {
        return array_map(static fn (string $text): Block => new Block(BlockKind::Paragraph, $text), self::FRENCH_PLAIN);
    }

    public static function frenchHtml(): string
    {
        return '<div id="readability-page-1" class="page"><div>
            <h1>Le retour des trains de nuit en Europe</h1>
            <p>Après des années de déclin, les trains de nuit reviennent en Europe. Selon une étude publiée en 2023 par
            <a href="https://example.org/agence" title="Agence">l’Agence européenne</a>, la fréquentation a augmenté
            de 3,5 % en un an<sup id="fnref1"><a href="#fn1">1</a></sup>. Le réseau compte désormais 12 000 kilomètres
            de lignes et transporte 1 500 000 voyageurs par an.</p>
            <figure><img src="https://example.org/train.jpg" alt="Un train de nuit en gare">
            <figcaption>Un train de nuit en gare, photo de l’agence</figcaption></figure>
            <h2>Un modèle économique fragile</h2>
            <p>M. Bernard, directeur de la Compagnie, explique que le billet moyen coûte 89,90 € et que la rentabilité
            reste faible. Mme Lefèvre, économiste, rappelle que le Dr Martin avait prédit ce retour au XXIe siècle,
            lors du 1er congrès européen du rail. Le Dr. Lefèvre est venu ensuite. Contact :
            <a href="mailto:presse@example.org">presse@example.org</a> ou https://example.org/presse.</p>
            <p>Le <strong>prix</strong> de l’énergie a augmenté de 12,5&nbsp;% depuis le 2e trimestre.</p>
            <pre><code class="language-python">print("ce code ne doit pas être lu")</code></pre>
            <script>document.write("ce script ne doit pas être lu")</script>
            <p>Les lignes principales :</p>
            <ul>
                <li>Paris-Berlin, trois fois par semaine</li>
                <li>Paris-Vienne, tous les jours</li>
                <li><input type="checkbox" disabled> Bruxelles-Prague, à partir de la 2nde moitié de l’année</li>
            </ul>
            <table><thead><tr><th>Ligne</th><th>Fréquence</th></tr></thead>
            <tbody><tr><td>Paris-Berlin</td><td>trois par semaine</td></tr></tbody></table>
            <blockquote><p>Le train de nuit, c’est la lenteur choisie, etc. Il faut le défendre.</p></blockquote>
            <p>'.self::FRENCH_LONG_SENTENCE.'.</p>
            <p>'.self::FRENCH_COMMA_SENTENCE.'</p>
            <section role="doc-endnotes"><hr><ol><li id="fn1"><p>Source : rapport annuel de l’agence.
            <a href="#fnref1" role="doc-backlink">&#8617;</a></p></li></ol></section>
        </div></div>';
    }

    public static function englishHtml(): string
    {
        return '<div id="readability-page-1" class="page"><div>
            <h1>The return of night trains in Europe</h1>
            <p>After years of decline, night trains are coming back to Europe. According to a study published in 2023
            by <a href="https://example.org/agency">the European Agency</a>, ridership grew by 3.5% in one
            year<sup><a href="#fn1">[1]</a></sup>. The network now covers 12,000 kilometers and carries 1,500,000
            passengers a year.</p>
            <h2>A fragile business model</h2>
            <p>Mr. Smith, the head of the company, says that the average ticket costs €89.90 and that margins stay low.
            Dr. Jones met him in St. Louis, e.g. at the 2nd rail forum. Write to press@example.org or visit
            <a href="https://example.org/press">https://example.org/press</a> for details.</p>
            <pre><code>let hidden = "this code must not be read"</code></pre>
            <ul>
                <li>Paris to Berlin, three times a week</li>
                <li>Paris to Vienna, every day</li>
            </ul>
            <p>'.self::ENGLISH_LONG_SENTENCE.'</p>
            <ol><li id="fn:1">Source: annual report.</li></ol>
        </div></div>';
    }

    public static function page(string $name): string
    {
        $html = file_get_contents(\dirname(__DIR__).'/Fixtures/pages/'.$name);
        if (false === $html) {
            throw new \RuntimeException(\sprintf('No fixture page "%s".', $name));
        }

        return $html;
    }

    private const string FRENCH_WORDS = 'le la les un une des train nuit voyageur couchette gare quai fenêtre paysage ville matin soir route '
        .'capitale contrôleur billet wagon couloir valise café enfant locomotive lumière réseau ligne Europe '
        .'lentement toujours parfois souvent déjà encore très plus moins avec sans pour dans entre vers sous '
        .'regarder partir arriver dormir réveiller attendre traverser raconter choisir défendre économique '
        .'fragile européen lointain étroit tiède vide inconnu heureux fatigué été hiver où à ça d’autres l’homme';

    private const string ENGLISH_WORDS = 'the a an train night traveler berth station platform window landscape city morning evening road capital '
        .'conductor ticket carriage corridor suitcase coffee child locomotive light network line Europe slowly '
        .'always sometimes often already still very more less with without for into between toward under look '
        .'leave arrive sleep wake wait cross tell choose defend economic fragile european distant narrow lukewarm '
        .'empty unknown happy tired summer winter where';

    /**
     * Paragraphs of random sentences: short ones, long ones without any mark, ones with commas
     * only, and rare tokens longer than any limit (RandomText of the Swift tests, other generator).
     *
     * @return list<Block>
     */
    public static function randomBlocks(Language $language, int $seed): array
    {
        $random = new Randomizer(new Xoshiro256StarStar($seed));
        $words = explode(' ', Language::French === $language ? self::FRENCH_WORDS : self::ENGLISH_WORDS);
        $pick = static fn (array $items): string => $items[$random->getInt(0, \count($items) - 1)];
        $blocks = [];
        for ($p = 0, $paragraphs = $random->getInt(4, 10); $p < $paragraphs; ++$p) {
            $sentences = [];
            for ($s = 0, $sentenceCount = $random->getInt(1, 6); $s < $sentenceCount; ++$s) {
                $count = 1 === $random->getInt(1, 10) ? $random->getInt(60, 160) : $random->getInt(3, 30);
                $markRate = $random->getInt(0, 3);
                $sentence = [];
                for ($i = 0; $i < $count; ++$i) {
                    $word = $pick($words);
                    if (0 === $random->getInt(0, 399)) {
                        $word = str_repeat('abcdefghij', $random->getInt(13, 50));
                    }
                    if (0 === $i) {
                        $word = mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
                    }
                    if ($markRate > 0 && $i < $count - 1 && $random->getInt(0, 11) < $markRate) {
                        $word .= $pick([',', ' ;', ' :', ',']);
                    }
                    $sentence[] = $word;
                }
                $sentences[] = implode(' ', $sentence).$pick(['.', ' !', ' ?', '.', '']);
            }
            $blocks[] = new Block(BlockKind::Paragraph, implode(' ', $sentences));
        }

        return $blocks;
    }

    /**
     * The lower-case words with their letters and digits only, as the Swift tests compare them.
     *
     * @return list<string>
     */
    public static function words(string $text): array
    {
        $words = [];
        foreach (preg_split('/\s+/u', $text, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $word = mb_strtolower((string) preg_replace('/[^\p{L}\p{N}]/u', '', $token));
            if ('' !== $word) {
                $words[] = $word;
            }
        }

        return $words;
    }
}

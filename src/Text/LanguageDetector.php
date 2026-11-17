<?php

declare(strict_types=1);

namespace App\Text;

/**
 * Selects the reading language of an article (R-LG-2 to R-LG-5), without NLLanguageRecognizer.
 *
 * The detector counts the function words of French, English and five close languages in the
 * first 2000 characters. A word shared by several languages ("de", "la", "en") counts for each
 * of them in part. The confidence is the share of the best language against the second one: a
 * confident detection decides alone, even for an unsupported language (R-LG-3); otherwise the
 * first two letters of the hint decide (R-LG-4).
 */
final readonly class LanguageDetector
{
    public const int SAMPLE_LENGTH = 2000;
    public const float MINIMUM_CONFIDENCE = 0.8;

    /**
     * Below this number of function words, the text is too short to decide.
     */
    public const int MINIMUM_HITS = 4;

    /**
     * Below this share of function words among the words, the text is not in a known language.
     */
    public const float MINIMUM_DENSITY = 0.15;

    private const array STOPWORDS = [
        'fr' => 'le la les l un une des du de d et est sont été être a ont avait dans pour par sur avec sans sous chez entre
            vers ce cet cette ces c il ils elle elles on nous vous je j me te se s leur leurs lui son sa ses mon ma mes
            notre nos votre vos qui que qu quoi dont où ne n pas plus aussi très tout tous toute toutes mais ou donc
            car comme si y en à au aux depuis avant après encore déjà même fait peut cela ça',
        'en' => 'the a an and of to in is are was were be been being has have had do does did not no but or if
            then than that this these those there it its they them their he him his she her we us our you your
            i my me who whom which what when where why how will would can could should may might must with
            from by at on for about into over after before also more most such only very s t ll ve re',
        'es' => 'el la los las un una unos unas de del y que en es son fue ser por para con sin sobre entre se
            no su sus lo al como más pero muy está están ha han hay este esta estos estas ya también',
        'it' => 'il lo la i gli le un una di del della delle dei degli nel nella che e è sono per con non si da
            dal alla al anche più ma come questo questa ha hanno essere stato',
        'pt' => 'o a os as um uma de do da dos das em no na nos nas que e é são para com por não se seu sua
            ao mais mas como foi ele ela também muito muitos muita pelo pela isso essa esse está estão
            ainda onde quando depois seus suas nós eles elas há',
        'de' => 'der die das den dem ein eine einen und ist sind war nicht zu mit von auf für im in es sie er
            wir ich dass wird werden bei nach aus wie oder aber auch sich hat haben',
        'nl' => 'de het een en van in is zijn was dat op te met voor niet aan er ook als maar bij door om nog
            wordt worden dit die naar uit wel heeft hebben veel hem hun ze zij wij je wat kan zal deze
            geen nu tot ik',
    ];

    public function detect(string $text, ?string $hint = null): ?Language
    {
        $guess = $this->guess($text);
        if (null !== $guess && $guess['confidence'] >= self::MINIMUM_CONFIDENCE) {
            return Language::tryFrom($guess['code']);
        }

        return null === $hint ? null : self::fromCode($hint);
    }

    /**
     * The most probable language code of the first 2000 characters and its confidence (0 to 1),
     * or null when the text has too few function words.
     *
     * @return array{code: string, confidence: float}|null
     */
    public function guess(string $text): ?array
    {
        $sample = mb_strtolower(mb_substr($text, 0, self::SAMPLE_LENGTH));
        preg_match_all('/\p{L}+/u', $sample, $matches);
        $words = $matches[0];
        if ([] === $words) {
            return null;
        }

        $index = self::index();
        $scores = array_fill_keys(array_keys(self::STOPWORDS), 0.0);
        $hits = 0;
        foreach ($words as $word) {
            $languages = $index[$word] ?? [];
            if ([] === $languages) {
                continue;
            }
            ++$hits;
            foreach ($languages as $code) {
                $scores[$code] += 1 / \count($languages);
            }
        }
        if ($hits < self::MINIMUM_HITS || $hits / \count($words) < self::MINIMUM_DENSITY) {
            return null;
        }

        arsort($scores);
        [$best, $second] = array_values($scores);
        $code = (string) array_key_first($scores);

        return ['code' => $code, 'confidence' => $best / ($best + $second)];
    }

    /**
     * "fr-FR", "FR", "en_US" give a language; other values give null.
     */
    public static function fromCode(string $code): ?Language
    {
        return Language::tryFrom(strtolower(substr(trim($code), 0, 2)));
    }

    /**
     * @return array<string, list<string>> the languages of each function word
     */
    private static function index(): array
    {
        /** @var array<string, list<string>>|null $index */
        static $index = null;
        if (null === $index) {
            $sets = [];
            foreach (self::STOPWORDS as $code => $words) {
                foreach (preg_split('/\s+/u', trim($words), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                    $sets[$word][$code] = $code;
                }
            }
            $index = array_map(array_values(...), $sets);
        }

        return $index;
    }
}

<?php

declare(strict_types=1);

namespace App\Command\Dev;

use App\Entity\Article;
use App\Entity\Segment;
use App\Enum\ArticleSource;
use App\Enum\ArticleStatus;
use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use App\Repository\ArticleRepository;
use App\Speech\AudioStore;
use App\Speech\VoiceCatalog;
use App\Speech\Wav;
use App\Text\BlockKind;
use App\Text\Language;
use App\Text\PreparedArticle;
use App\Text\PreparedSegment;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Fills the library with sample articles in every state, for working on the interface before
 * the pipeline runs. The audio is silence only, at 8 kHz: the player has real files to play,
 * of the right durations, and nothing can be heard.
 */
#[When(env: 'dev')]
#[AsCommand(name: 'app:dev:seed', description: 'Adds sample articles in every state, with silent audio')]
final readonly class SeedCommand
{
    private const int SAMPLE_RATE = 8000;

    public function __construct(
        private ArticleRepository $articles,
        private AudioStore $audio,
        private VoiceCatalog $voices,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Synthesize this many more chunks of the articles being synthesized, to see the player resume')]
        int $advance = 0,
    ): int {
        if ($advance > 0) {
            return $this->advance($io, $advance);
        }

        $now = $this->clock->now();
        $created = 0;
        foreach (self::samples() as $index => $sample) {
            if (null !== $this->articles->findOneBy(['url' => $sample['url']])) {
                continue;
            }

            $this->articles->save($this->build($sample, $now->modify(\sprintf('-%d minutes', 12 + 47 * $index))));
            ++$created;
        }

        $io->success(0 === $created ? 'The samples are already in the library.' : \sprintf('%d sample articles added.', $created));
        $io->note('Run "bin/console app:dev:seed --advance=3" to synthesize three more chunks of the article in progress.');

        return 0;
    }

    private function advance(SymfonyStyle $io, int $count): int
    {
        $advanced = 0;
        foreach ($this->articles->findBy(['status' => ArticleStatus::Synthesizing]) as $article) {
            $language = $article->getLanguage() ?? Language::English;
            $left = $count;
            foreach ($article->getSegments() as $segment) {
                if ($left > 0 && !$segment->isReady()) {
                    $this->attachSilence($segment, $language);
                    --$left;
                    ++$advanced;
                }
            }
            if ($article->countReadySegments() === $article->getSegments()->count()) {
                $article->markReady($this->clock->now());
            }
            $this->articles->save($article);
        }

        $io->success(\sprintf('%d chunks synthesized.', $advanced));

        return 0;
    }

    /**
     * @param array{url: string, source: ArticleSource, setting: LanguageSetting, gender: VoiceGender, state: string, failure?: string, language?: Language, title?: string, site?: string, author?: string, summary?: string, editor?: string, ready?: int, blocks?: list<array{0: BlockKind, 1: string}>, edits?: array<string, string>} $sample
     */
    private function build(array $sample, \DateTimeImmutable $at): Article
    {
        $article = new Article($sample['url'], $sample['setting'], $sample['gender'], $this->voices->defaultEngine()->name, $sample['source'], $at);
        if ('queued' === $sample['state']) {
            return $article;
        }

        $article->startExtraction($at);
        if ('failed' === $sample['state']) {
            $article->fail($sample['failure'] ?? 'extraction.timeout', $at->modify('+20 seconds'));

            return $article;
        }

        $language = $sample['language'] ?? Language::English;
        $title = $sample['title'] ?? 'Untitled';
        $article->describe($title, $sample['site'] ?? null, $sample['author'] ?? null, null, null, $at->modify('-2 days'), $at);

        $prepared = self::prepare($title, $language, $sample['blocks'] ?? [], $sample['edits'] ?? []);
        $editor = $sample['editor'] ?? 'rules';
        $article->prepare($prepared, $this->voices->voice($article->getVoiceEngine(), $language, $sample['gender'])->name, $editor, $at);
        $article->edit($editor, $sample['summary'] ?? null, $at);

        $ready = $sample['ready'] ?? \PHP_INT_MAX;
        foreach ($article->getSegments() as $segment) {
            if ($segment->getSpoken() !== $segment->getText()) {
                $segment->rewrite($segment->getSpoken());
            }
            if ($segment->getPosition() < $ready) {
                $this->attachSilence($segment, $language);
            }
        }

        if ('ready' === $sample['state']) {
            $article->markReady($at->modify('+1 minute'));
        }

        return $article;
    }

    private function attachSilence(Segment $segment, Language $language): void
    {
        // About 15 characters a second in English, a little slower in French.
        $milliseconds = max(700, (int) round(mb_strlen($segment->getSpoken()) * (Language::French === $language ? 68 : 62)));
        $key = hash('xxh128', 'crieur-seed-silence-'.$milliseconds);
        if (!$this->audio->has($key)) {
            $this->audio->write($key, Wav::silence($milliseconds, self::SAMPLE_RATE)->toBytes());
        }
        $segment->attachAudio($key, $milliseconds);
    }

    /**
     * @param list<array{0: BlockKind, 1: string}> $blocks
     * @param array<string, string>                $edits  the speech editor rewrites: written text => spoken text
     */
    private static function prepare(string $title, Language $language, array $blocks, array $edits): PreparedArticle
    {
        $segments = [new PreparedSegment(0, BlockKind::Title, $title, strtr($title, $edits), 900)];
        $words = str_word_count($title);
        foreach ($blocks as $index => [$kind, $text]) {
            $sentences = preg_split('/(?<=[.!?…:;])\s+(?=[\p{Lu}«"“])/u', $text) ?: [$text];
            $last = \count($sentences) - 1;
            foreach ($sentences as $number => $sentence) {
                $pause = $number < $last ? 280 : (BlockKind::Heading === $kind ? 900 : 700);
                $segments[] = new PreparedSegment($index + 1, $kind, $sentence, strtr($sentence, $edits), $pause);
                $words += \count(preg_split('/\s+/u', $sentence) ?: []);
            }
        }

        return new PreparedArticle($title, $language, $segments, \count($blocks) + 1, $words);
    }

    /**
     * French spacing: a no-break space before ":" and inside quotes, a narrow one before ";", "!" and "?".
     */
    private static function french(string $text): string
    {
        return strtr($text, [' :' => "\u{A0}:", ' ;' => "\u{202F};", ' !' => "\u{202F}!", ' ?' => "\u{202F}?", '« ' => "«\u{A0}", ' »' => "\u{A0}»", ' %' => "\u{A0}%", ' €' => "\u{A0}€", '1 200' => "1\u{202F}200"]);
    }

    /**
     * @return list<array{url: string, source: ArticleSource, setting: LanguageSetting, gender: VoiceGender, state: string, failure?: string, language?: Language, title?: string, site?: string, author?: string, summary?: string, editor?: string, ready?: int, blocks?: list<array{0: BlockKind, 1: string}>, edits?: array<string, string>}>
     */
    private static function samples(): array
    {
        $p = BlockKind::Paragraph;
        $h = BlockKind::Heading;
        $q = BlockKind::Quote;
        $li = BlockKind::ListItem;
        $fr = self::french(...);

        return [
            [
                'url' => 'https://outdoor-notes.example/2026/11/listening-while-you-walk',
                'source' => ArticleSource::Bookmarklet,
                'setting' => LanguageSetting::Auto,
                'gender' => VoiceGender::Male,
                'state' => 'synthesizing',
                'language' => Language::English,
                'title' => 'A field guide to listening while you walk',
                'site' => 'Outdoor Notes',
                'author' => 'Sam Okafor',
                'ready' => 7,
                'blocks' => [
                    [$p, 'Walking and listening go well together. The body finds a rhythm, the mind settles, and a long article becomes a companion for the next half hour.'],
                    [$p, 'Choose a route you know. Familiar streets ask for less attention, which leaves more of it for the story in your ears.'],
                    [$h, 'Set the pace'],
                    [$p, 'Most voices sound natural between normal speed and one and a quarter. Faster than that, the meaning starts to blur, especially on dense writing.'],
                    [$p, 'If you lose the thread, go back one paragraph. It costs a few seconds and saves the whole argument.'],
                    [$h, 'Know when to stop'],
                    [$p, 'A crossing, a conversation, a view worth looking at: pause without guilt. The article will wait exactly where you left it.'],
                    [$p, 'At home, the transcript is still there. The sentences you heard are easy to find again, and the ones you missed are only a click away.'],
                ],
            ],
            [
                'url' => 'https://lettres-du-matin.example/2026/11/le-retour-du-feuilleton',
                'source' => ArticleSource::Web,
                'setting' => LanguageSetting::French,
                'gender' => VoiceGender::Female,
                'state' => 'queued',
            ],
            [
                'url' => 'https://slow-journal.example/essays/the-quiet-return-of-reading-aloud',
                'source' => ArticleSource::Web,
                'setting' => LanguageSetting::Auto,
                'gender' => VoiceGender::Female,
                'state' => 'ready',
                'language' => Language::English,
                'title' => 'The quiet return of reading aloud',
                'site' => 'Slow Journal',
                'author' => 'Clara Whitfield',
                'editor' => 'ministral-3:8b',
                'summary' => 'Listening to long articles is coming back, for reasons much older than the technology behind it.',
                'blocks' => [
                    [$p, 'For most of history, reading was something people did out loud. Monks murmured over manuscripts, families gathered around a single newspaper, and letters were read to whoever happened to be in the room. Silent reading, the kind we now take for granted, is a fairly recent habit.'],
                    [$p, 'Something interesting is happening in 2026. More people choose to listen to the articles they save, rather than read them on a screen. They listen while cooking, walking the dog or commuting, and they say they remember more than they expected.'],
                    [$h, 'Why the ear is a good reader'],
                    [$p, 'Listening sets a pace. You cannot skim a sentence that is being read to you, and you cannot jump to the conclusion without hearing the argument first. That small constraint turns out to be a gift for long, careful writing.'],
                    [$q, 'A voice gives a text a tempo, and a tempo gives the mind room to think.'],
                    [$p, 'Good narration also carries structure. A pause before a new section, a slight change of tone for a quotation, a breath at the end of a paragraph: these cues tell the listener where they are, as headings and white space do on the page.'],
                    [$h, 'What makes a text easy to hear'],
                    [$p, 'Not every article is written for the ear. A few habits make a big difference.'],
                    [$li, 'Spell out numbers and units, so that 3.5 km is heard as a distance.'],
                    [$li, 'Expand abbreviations such as e.g. the first time they appear.'],
                    [$li, 'Replace symbols such as % or & with words.'],
                    [$p, 'None of this changes what the author says. It only changes how the words reach you, and that is often enough to turn a saved article into one you actually finish.'],
                ],
                'edits' => [
                    'in 2026' => 'in twenty twenty-six',
                    '3.5 km' => 'three and a half kilometres',
                    'such as e.g. the' => 'such as "for example" the',
                    '% or &' => 'per cent or ampersand',
                ],
            ],
            [
                'url' => 'https://paywalled-daily.example/premium/the-long-read',
                'source' => ArticleSource::Mcp,
                'setting' => LanguageSetting::Auto,
                'gender' => VoiceGender::Female,
                'state' => 'failed',
                'failure' => 'extraction.http_error',
            ],
            [
                'url' => 'https://revue-des-usages.example/ecouter-un-article',
                'source' => ArticleSource::Mcp,
                'setting' => LanguageSetting::Auto,
                'gender' => VoiceGender::Female,
                'state' => 'ready',
                'language' => Language::French,
                'title' => $fr('Écouter un article, est-ce encore lire ?'),
                'site' => 'Revue des usages',
                'author' => 'Hélène Marchand',
                'editor' => 'mistral-small-latest',
                'summary' => 'Écouter un texte mobilise la même compréhension que le lire, à condition que la voix respecte son rythme.',
                'blocks' => [
                    [$p, $fr('Chaque matin, des milliers de personnes écoutent les articles qu’elles n’ont pas eu le temps de lire la veille. Dans le métro, en cuisine ou en promenade, la voix remplace l’écran.')],
                    [$p, $fr('Les chercheurs observent depuis longtemps que la compréhension orale et la compréhension écrite reposent sur des mécanismes très proches. Ce qui change, c’est le rythme : à l’oral, c’est la voix qui le fixe.')],
                    [$h, 'Une voix qui respecte le texte'],
                    [$p, $fr('Une bonne lecture à voix haute ne se contente pas de prononcer les mots. Elle marque les pauses, ralentit avant une citation et laisse respirer la fin des paragraphes.')],
                    [$q, $fr('« Lire, c’est d’abord entendre une voix », écrivait déjà un critique au XIXe siècle.')],
                    [$p, $fr('Pour qu’un texte s’écoute bien, quelques règles simples suffisent :')],
                    [$li, $fr('écrire les montants en toutes lettres, par exemple 1 200 € ;')],
                    [$li, $fr('développer les sigles comme SNCF ou OCDE ;')],
                    [$li, 'éviter les parenthèses trop longues.'],
                    [$p, 'Bien préparé, un article de dix minutes se suit sans effort, du premier au dernier mot.'],
                ],
                'edits' => [
                    'XIXe siècle' => 'dix-neuvième siècle',
                    $fr('1 200 €') => 'mille deux cents euros',
                    'SNCF ou OCDE' => 'S.N.C.F. ou O.C.D.E.',
                ],
            ],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Editor;

use App\Text\Language;
use App\Text\TextNormalizer;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * The speech editor: a language model that rewrites chunks of an article for the ear (dates,
 * decimals, units, abbreviations, symbols, leftovers of the page), through a Symfony AI agent with
 * structured output.
 *
 * Its answer is checked before use: a chunk that comes back missing, or much shorter or longer
 * than the original, keeps the text of the deterministic rules. The model never invents content
 * that the listener would hear.
 */
final class SpeechEditor
{
    /**
     * The model each agent of config/packages/ai.yaml uses, shown to the listener.
     */
    public const array MODELS = [
        'mistral' => 'mistral-small-latest',
        'ollama' => 'ministral-3:8b',
    ];

    /**
     * Ollama loads a model with its largest context by default: hundreds of thousands of tokens of
     * cache that no longer fit in memory, and a model ten times slower. A batch needs a few thousand.
     */
    private const array OPTIONS = [
        'mistral' => ['temperature' => 0.0],
        'ollama' => ['temperature' => 0.0, 'num_ctx' => 8192, 'keep_alive' => '30m'],
    ];

    public const int FIRST_BATCH_CHARACTERS = 120;
    public const int BATCH_CHARACTERS = 1200;
    public const int BATCH_CHUNKS = 16;

    public function __construct(
        #[AutowireLocator(['mistral' => new Autowire(service: 'ai.agent.speech_editor_mistral'), 'ollama' => new Autowire(service: 'ai.agent.speech_editor_ollama')])]
        private readonly ContainerInterface $agents,
        #[Autowire(param: 'crieur.editor')]
        private readonly string $mode,
        #[Autowire(env: 'MISTRAL_API_KEY')]
        private readonly string $mistralApiKey,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The agent in use: "mistral", "ollama", or null when only the deterministic rules apply.
     */
    public function backend(): ?string
    {
        return match ($this->mode) {
            'mistral', 'ollama' => $this->mode,
            'auto' => '' !== trim($this->mistralApiKey) ? 'mistral' : null,
            default => null,
        };
    }

    public function model(): ?string
    {
        $backend = $this->backend();

        return null === $backend ? null : self::MODELS[$backend];
    }

    /**
     * @param array<int, string> $chunks the display text of each chunk, by id, in reading order
     *
     * @throws EditorUnavailable when the model cannot be reached or answers nonsense
     */
    public function edit(Language $language, string $title, array $chunks, bool $withSummary): EditedBatch
    {
        $backend = $this->backend() ?? throw new EditorUnavailable('No speech editor is enabled.');
        $agent = $this->agents->get($backend);
        \assert($agent instanceof AgentInterface);

        $request = json_encode([
            'language' => $language->value,
            'title' => $title,
            'summary' => $withSummary,
            'chunks' => array_map(static fn (int $id, string $text): array => ['id' => $id, 'text' => $text], array_keys($chunks), $chunks),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        try {
            $answer = $agent->call($request, ['response_format' => SpokenBatch::class] + self::OPTIONS[$backend])->asObject(SpokenBatch::class);
        } catch (\Throwable $e) {
            $this->logger->warning('The speech editor failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);

            throw new EditorUnavailable($e->getMessage(), previous: $e);
        }

        $spoken = [];
        foreach ($answer->chunks as $chunk) {
            if (\array_key_exists($chunk->id, $chunks) && self::isFaithful($chunks[$chunk->id], $chunk->spoken, $language)) {
                $spoken[$chunk->id] = self::keepCase($chunks[$chunk->id], self::clean($chunk->spoken));
            }
        }

        if (\count($spoken) < \count($chunks) / 2) {
            $this->logger->warning('The speech editor answered {answered} of {asked} chunks faithfully: the batch keeps the rules.', ['answered' => \count($spoken), 'asked' => \count($chunks)]);
            $spoken = [];
        }

        $summary = null !== $answer->summary ? self::clean($answer->summary) : null;

        return new EditedBatch($spoken, '' === $summary ? null : $summary);
    }

    /**
     * The size of the next batch: small at first, so that the listener does not wait after the
     * title, then as much as is already edited (the edited text doubles at each batch), up to BATCH_CHARACTERS. A voice reads about 15
     * characters a second and even a local model edits faster than that, so the editor keeps
     * ahead of the voice once it has a head start.
     */
    public static function batchCharacters(int $alreadyEdited): int
    {
        return max(self::FIRST_BATCH_CHARACTERS, min(self::BATCH_CHARACTERS, $alreadyEdited));
    }

    /**
     * Rewriting for the ear changes the length a little ("17/11" becomes "17 novembre"), never a
     * lot: a much shorter or longer answer means the model summarized or added content.
     */
    public static function isFaithful(string $original, string $spoken, Language $language = Language::English): bool
    {
        $originalLength = mb_strlen(trim($original));
        $spokenLength = mb_strlen(trim($spoken));

        if ($spokenLength > 0 && !self::keepsNumbers($original, $spoken, $language)) {
            return false;
        }

        if (0 === $spokenLength) {
            // Dropping a chunk is allowed for short leftovers only ("Read more", "[1]").
            return $originalLength <= 60;
        }

        if ($originalLength < 40) {
            return $spokenLength <= 4 * $originalLength + 40;
        }

        return $spokenLength >= 0.6 * $originalLength && $spokenLength <= 2.5 * $originalLength;
    }

    /**
     * A model can misread a number ("18th" became "eighteen hundredth" once). Each number of the
     * original must come back in digits, or spelled out exactly as the deterministic rules spell it
     * ("18th" as "eighteenth", "3.5%" as "three point five percent"). Dates, times and version
     * numbers are free, and footnote marks may go.
     */
    public static function keepsNumbers(string $original, string $spoken, Language $language = Language::English): bool
    {
        $exempt = [
            '~\[\d{1,3}\]~u',                              // footnote marks
            '~\b\d{1,4}[/.\-]\d{1,2}[/.\-]\d{1,4}\b~u',      // dates
            '~\b\d{1,2}[:h]\d{2}\b~u',                      // times
            '~\b\p{Lu}[\p{L}\d+#]*[ \x{A0}]v?\d+(?:\.\d+)+[\w-]*|\bv\d+(?:\.\d+)+[\w-]*~u', // versions: "PHP 8.5", "v1.0.0"
        ];
        $numbers = static fn (string $text): array => preg_match_all('~\d+(?:[.,]\d+)*~u', $text, $matches) ? $matches[0] : [];

        $kept = (string) preg_replace($exempt, ' ', $original);
        $available = $numbers($spoken);
        $words = self::comparable($spoken);
        $normalizer = new TextNormalizer();
        preg_match_all('~\d+(?:[.,]\d+)*(?:st|nd|rd|th|er|re|e|ème|[ \x{A0}\x{202F}]?[%€$£])?(?!\p{L})~u', $kept, $tokens);
        foreach ($tokens[0] as $token) {
            $number = $numbers($token)[0] ?? $token;
            $index = array_search($number, $available, true);
            if (false !== $index) {
                unset($available[$index]);
                continue;
            }
            if (!str_contains($words, self::comparable($normalizer->normalize($token, $language)))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lower case letters and single spaces only, to compare spellings ("Forty-two" and "forty two").
     */
    private static function comparable(string $text): string
    {
        return trim((string) preg_replace('~[^\p{L}\d]+~u', ' ', mb_strtolower($text)));
    }

    /**
     * A chunk that continues a sentence starts in lower case: the model tends to capitalize it.
     */
    private static function keepCase(string $original, string $spoken): string
    {
        $first = mb_substr(ltrim($original), 0, 1);
        if ('' !== $spoken && $first === mb_strtolower($first) && $first !== mb_strtoupper($first)) {
            return mb_strtolower(mb_substr($spoken, 0, 1)).mb_substr($spoken, 1);
        }

        return $spoken;
    }

    private static function clean(string $text): string
    {
        $text = str_replace(["\u{2014}", "\u{2013}", '*', '_', '`', '#'], [', ', '-', '', ' ', '', ''], $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}

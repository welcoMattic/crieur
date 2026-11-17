<?php

declare(strict_types=1);

namespace App\Editor;

use App\Text\Language;
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
            if (\array_key_exists($chunk->id, $chunks) && self::isFaithful($chunks[$chunk->id], $chunk->spoken)) {
                $spoken[$chunk->id] = self::clean($chunk->spoken);
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
     * Rewriting for the ear changes the length a little ("17/11" becomes "17 novembre"), never a
     * lot: a much shorter or longer answer means the model summarized or added content.
     */
    public static function isFaithful(string $original, string $spoken): bool
    {
        $originalLength = mb_strlen(trim($original));
        $spokenLength = mb_strlen(trim($spoken));

        if (0 === $spokenLength) {
            // Dropping a chunk is allowed for short leftovers only ("Read more", "[1]").
            return $originalLength <= 60;
        }

        if ($originalLength < 40) {
            return $spokenLength <= 4 * $originalLength + 40;
        }

        return $spokenLength >= 0.6 * $originalLength && $spokenLength <= 2.5 * $originalLength;
    }

    private static function clean(string $text): string
    {
        $text = str_replace(["\u{2014}", "\u{2013}", '*', '_', '`', '#'], [', ', '-', '', ' ', '', ''], $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}

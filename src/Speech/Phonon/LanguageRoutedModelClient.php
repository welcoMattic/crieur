<?php

declare(strict_types=1);

namespace App\Speech\Phonon;

use App\Speech\PhononServers;
use Symfony\AI\Platform\Bridge\Ollama\TextToSpeech\ModelClient;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends each speech request to the text-to-speech client of the Ollama bridge, on the server of
 * its language: Ollama serves Phonon on Apple Silicon only, elsewhere the Phonon servers of Gradium
 * answer the same request, one per language. The "language" option picks the server and is not
 * sent; a language without a server of its own goes to Ollama.
 */
final readonly class LanguageRoutedModelClient implements ModelClientInterface
{
    private ModelClient $ollama;

    /** @var array<string, ModelClient> */
    private array $servers;

    public function __construct(HttpClientInterface $httpClient, string $endpoint, PhononServers $servers)
    {
        $this->ollama = new ModelClient(ScopingHttpClient::forBaseUri($httpClient, $endpoint));
        $this->servers = array_map(
            static fn (string $url): ModelClient => new ModelClient(ScopingHttpClient::forBaseUri($httpClient, $url)),
            $servers->servers,
        );
    }

    public function supports(Model $model): bool
    {
        return $this->ollama->supports($model);
    }

    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        $language = $options['language'] ?? null;
        unset($options['language']);

        return (\is_string($language) ? $this->servers[$language] ?? $this->ollama : $this->ollama)->request($model, $payload, $options);
    }
}

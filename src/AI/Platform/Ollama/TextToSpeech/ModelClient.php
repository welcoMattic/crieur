<?php

declare(strict_types=1);

namespace App\AI\Platform\Ollama\TextToSpeech;

use App\AI\Platform\Ollama\TextToSpeech;
use App\Speech\PhononServers;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Calls the OpenAI compatible speech endpoint of Ollama: POST /v1/audio/speech.
 *
 * Ollama answers WAV (24 kHz, 16-bit, mono for Phonon) and refuses more than 4096 characters.
 * Where Ollama cannot run Phonon (it does on Apple Silicon only), the Phonon servers of Gradium
 * answer the same request, one server per language: the "language" option picks it.
 */
final readonly class ModelClient implements ModelClientInterface
{
    public const int MAX_CHARACTERS = 4096;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $endpoint,
        private ?PhononServers $servers = null,
    ) {
    }

    public function supports(Model $model): bool
    {
        return $model instanceof TextToSpeech;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        if (!isset($options['voice']) || !\is_string($options['voice'])) {
            throw new InvalidArgumentException('The "voice" option is required for Ollama speech requests.');
        }

        $input = \is_string($payload) ? $payload : ($payload['text'] ?? null);
        if (!\is_string($input) || '' === trim($input)) {
            throw new InvalidArgumentException('The text to speak must be a non-empty string.');
        }

        if (mb_strlen($input) > self::MAX_CHARACTERS) {
            throw new InvalidArgumentException(\sprintf('Ollama speaks at most %d characters per request, %d given.', self::MAX_CHARACTERS, mb_strlen($input)));
        }

        $language = \is_string($options['language'] ?? null) ? $options['language'] : '';
        $endpoint = $this->servers?->for($language) ?? $this->endpoint;

        return new RawHttpResult($this->httpClient->request('POST', rtrim($endpoint, '/').'/v1/audio/speech', [
            'json' => [
                'model' => $model->getName(),
                'input' => $input,
                'voice' => $options['voice'],
                'response_format' => 'wav',
            ],
        ]));
    }
}

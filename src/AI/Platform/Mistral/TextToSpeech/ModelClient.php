<?php

declare(strict_types=1);

namespace App\AI\Platform\Mistral\TextToSpeech;

use App\AI\Platform\Mistral\TextToSpeech;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Calls the speech endpoint of Mistral AI: POST /v1/audio/speech, with a preset voice id.
 */
final readonly class ModelClient implements ModelClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[\SensitiveParameter] private string $apiKey,
        private string $baseUrl = 'https://api.mistral.ai',
    ) {
    }

    public function supports(Model $model): bool
    {
        return $model instanceof TextToSpeech;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        if ('' === trim($this->apiKey)) {
            throw new AuthenticationException('Voxtral needs a Mistral API key: set MISTRAL_API_KEY.');
        }

        if (!isset($options['voice']) || !\is_string($options['voice'])) {
            throw new InvalidArgumentException('The "voice" option (a Voxtral voice id) is required.');
        }

        $input = \is_string($payload) ? $payload : ($payload['text'] ?? null);
        if (!\is_string($input) || '' === trim($input)) {
            throw new InvalidArgumentException('The text to speak must be a non-empty string.');
        }

        return new RawHttpResult($this->httpClient->request('POST', rtrim($this->baseUrl, '/').'/v1/audio/speech', [
            'auth_bearer' => $this->apiKey,
            'headers' => ['Accept' => 'application/json, audio/wav'],
            'json' => [
                'model' => $model->getName(),
                'input' => $input,
                'voice_id' => $options['voice'],
                'response_format' => 'wav',
            ],
        ]));
    }
}

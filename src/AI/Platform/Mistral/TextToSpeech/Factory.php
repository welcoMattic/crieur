<?php

declare(strict_types=1);

namespace App\AI\Platform\Mistral\TextToSpeech;

use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the provider of the Voxtral speech models. The official Mistral bridge of Symfony AI
 * covers chat, embeddings, OCR and transcription, not speech yet.
 */
final class Factory
{
    public static function createProvider(
        #[\SensitiveParameter] string $apiKey,
        ?HttpClientInterface $httpClient = null,
        ?EventDispatcherInterface $eventDispatcher = null,
    ): ProviderInterface {
        return new Provider(
            'mistral-speech',
            [new ModelClient($httpClient ?? HttpClient::create(), $apiKey)],
            [new ResultConverter()],
            new ModelCatalog(),
            eventDispatcher: $eventDispatcher,
        );
    }
}

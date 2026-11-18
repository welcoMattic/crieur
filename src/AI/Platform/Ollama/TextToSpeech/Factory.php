<?php

declare(strict_types=1);

namespace App\AI\Platform\Ollama\TextToSpeech;

use App\Speech\PhononServers;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Builds the provider of the Ollama speech models, to sit next to the other providers of a platform.
 * With Phonon servers ("fr=http://...,en=http://..."), each language goes to its own server.
 */
final class Factory
{
    public static function createProvider(
        string $endpoint = 'http://127.0.0.1:11434',
        ?HttpClientInterface $httpClient = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $phononServers = '',
    ): ProviderInterface {
        return new Provider(
            'ollama-speech',
            [new ModelClient($httpClient ?? HttpClient::create(), $endpoint, PhononServers::fromString($phononServers))],
            [new ResultConverter()],
            new ModelCatalog(),
            eventDispatcher: $eventDispatcher,
        );
    }
}

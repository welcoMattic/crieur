<?php

declare(strict_types=1);

namespace App\Speech\Phonon;

use App\Speech\PhononServers;
use Symfony\AI\Platform\Bridge\Ollama\TextToSpeech;
use Symfony\AI\Platform\Bridge\Ollama\TextToSpeech\ResultConverter;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\ModelCatalog\InMemoryModelCatalog;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Phonon voices, through the text-to-speech of the Ollama bridge of Symfony AI.
 *
 * Its catalog knows the speech model without asking Ollama ("phonon:40m-multilingual" resolves to
 * "phonon"): no call to /api/show before each chunk, and the Phonon servers of Gradium, which have
 * no such endpoint, answer too.
 */
final class PhononProvider
{
    public static function create(
        string $endpoint = 'http://127.0.0.1:11434',
        ?HttpClientInterface $httpClient = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $phononServers = '',
    ): ProviderInterface {
        return new Provider(
            'phonon',
            [new LanguageRoutedModelClient($httpClient ?? HttpClient::create(), $endpoint, PhononServers::fromString($phononServers))],
            [new ResultConverter()],
            new InMemoryModelCatalog([
                'phonon' => [
                    'class' => TextToSpeech::class,
                    'capabilities' => [Capability::INPUT_TEXT, Capability::OUTPUT_AUDIO, Capability::TEXT_TO_SPEECH],
                ],
            ]),
            eventDispatcher: $eventDispatcher,
        );
    }
}

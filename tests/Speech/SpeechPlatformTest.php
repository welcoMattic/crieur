<?php

declare(strict_types=1);

namespace App\Tests\Speech;

use App\Enum\VoiceGender;
use App\Speech\AudioPostProcessor;
use App\Speech\AudioStore;
use App\Speech\Phonon\PhononProvider;
use App\Speech\SpeechSynthesizer;
use App\Speech\Voice;
use App\Speech\Wav;
use App\Text\Language;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Mistral\Factory as MistralFactory;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The speech platform as config/services.yaml builds it, on the text-to-speech of the Ollama and
 * Mistral bridges of Symfony AI; only HTTP is faked.
 */
final class SpeechPlatformTest extends TestCase
{
    private const string AUDIO = __DIR__.'/../../var/audio_test/speech-platform';

    /** @var list<array{url: string, body: array<string, mixed>, authorization: ?string}> */
    private array $requests = [];

    protected function tearDown(): void
    {
        new Filesystem()->remove(self::AUDIO);
    }

    #[Test]
    public function phononReadsThroughTheSpeechEndpointOfOllama(): void
    {
        $result = $this->synthesizer()->synthesize('Bonjour.', self::phonon('Tiphaine', Language::French));

        static::assertSame([[
            'url' => 'http://ollama:11434/v1/audio/speech',
            'body' => ['voice' => 'Tiphaine', 'response_format' => 'wav', 'model' => 'phonon:40m-multilingual', 'input' => 'Bonjour.'],
            'authorization' => null,
        ]], $this->requests);
        static::assertSame(500, $result['durationMs']);
    }

    #[Test]
    public function eachLanguageGoesToItsPhononServerWhenThereAreSome(): void
    {
        $synthesizer = $this->synthesizer('fr=http://127.0.0.1:8880/, en=http://127.0.0.1:8881');

        $synthesizer->synthesize('Bonjour.', self::phonon('Tiphaine', Language::French));
        $synthesizer->synthesize('Hello.', self::phonon('Hadley', Language::English));

        static::assertSame(['http://127.0.0.1:8880/v1/audio/speech', 'http://127.0.0.1:8881/v1/audio/speech'], array_column($this->requests, 'url'));
        static::assertArrayNotHasKey('language', $this->requests[0]['body'], 'The language picks the server, it is not sent.');
    }

    #[Test]
    public function aLanguageWithoutAServerOfItsOwnGoesToOllama(): void
    {
        $this->synthesizer('fr=http://127.0.0.1:8880')->synthesize('Hello.', self::phonon('Hadley', Language::English));

        static::assertSame(['http://ollama:11434/v1/audio/speech'], array_column($this->requests, 'url'));
    }

    #[Test]
    public function voxtralReadsThroughMistralAi(): void
    {
        $result = $this->synthesizer()->synthesize('Hello.', new Voice('voxtral', 'voxtral-mini-tts-latest', 'en_paul_neutral', 'Paul', Language::English, VoiceGender::Male));

        static::assertSame([[
            'url' => 'https://api.mistral.ai/v1/audio/speech',
            'body' => ['response_format' => 'wav', 'voice_id' => 'en_paul_neutral', 'model' => 'voxtral-mini-tts-latest', 'input' => 'Hello.'],
            'authorization' => 'Bearer mistral-key',
        ]], $this->requests);
        static::assertSame(500, $result['durationMs']);
    }

    #[Test]
    public function aRefusalBecomesAPlatformException(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['error' => ['message' => 'voice "Nobody" not found']], ['http_code' => 400]));
        $synthesizer = new SpeechSynthesizer(self::platform($httpClient, ''), new AudioStore(self::AUDIO), new AudioPostProcessor());

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('voice "Nobody" not found');

        $synthesizer->synthesize('Hello.', self::phonon('Nobody', Language::English));
    }

    private function synthesizer(string $phononServers = ''): SpeechSynthesizer
    {
        $wav = Wav::silence(500)->toBytes();
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use ($wav): MockResponse {
            $authorization = null;
            foreach ($options['headers'] as $header) {
                if (str_starts_with($header, 'Authorization: ')) {
                    $authorization = substr($header, \strlen('Authorization: '));
                }
            }
            $this->requests[] = ['url' => $url, 'body' => json_decode($options['body'], true), 'authorization' => $authorization];

            // Ollama answers the WAV file, Mistral AI a JSON document with the audio in base64.
            return str_starts_with($url, 'https://api.mistral.ai/')
                ? new JsonMockResponse(['audio_data' => base64_encode($wav)])
                : new MockResponse($wav, ['response_headers' => ['content-type' => 'audio/wav']]);
        });

        return new SpeechSynthesizer(self::platform($httpClient, $phononServers), new AudioStore(self::AUDIO), new AudioPostProcessor());
    }

    private static function platform(MockHttpClient $httpClient, string $phononServers): Platform
    {
        return new Platform([
            PhononProvider::create('http://ollama:11434', $httpClient, null, $phononServers),
            MistralFactory::createProvider('mistral-key', $httpClient, name: 'mistral-speech'),
        ], new CatalogBasedModelRouter());
    }

    private static function phonon(string $id, Language $language): Voice
    {
        return new Voice('phonon', 'phonon:40m-multilingual', $id, $id, $language, VoiceGender::Female);
    }
}

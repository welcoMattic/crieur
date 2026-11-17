<?php

declare(strict_types=1);

namespace App\Tests\AI\Platform;

use App\AI\Platform\Ollama\TextToSpeech\Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OllamaTextToSpeechTest extends TestCase
{
    private const string WAV = "RIFF\x24\x00\x00\x00WAVEfmt ";

    #[Test]
    public function itSendsTheTextAndVoiceToTheSpeechEndpointOfOllama(): void
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            static::assertSame('POST', $method);
            static::assertSame('http://ollama:11434/v1/audio/speech', $url);
            static::assertSame(['model' => 'phonon:40m-multilingual', 'input' => 'Bonjour.', 'voice' => 'Tiphaine', 'response_format' => 'wav'], json_decode($options['body'], true));

            return new MockResponse(self::WAV, ['response_headers' => ['content-type' => 'audio/wav']]);
        });
        $platform = new Platform([Factory::createProvider('http://ollama:11434/', $httpClient)], new CatalogBasedModelRouter());

        $result = $platform->invoke('phonon:40m-multilingual', 'Bonjour.', ['voice' => 'Tiphaine'])->getResult();

        static::assertInstanceOf(BinaryResult::class, $result);
        static::assertSame(self::WAV, $result->getContent());
        static::assertSame('audio/wav', $result->getMimeType());
    }

    #[Test]
    public function itTurnsARefusalIntoAPlatformException(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['error' => ['message' => 'voice "Nobody" not found']], ['http_code' => 400]));
        $platform = new Platform([Factory::createProvider('http://ollama:11434', $httpClient)], new CatalogBasedModelRouter());

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('voice "Nobody" not found');

        $platform->invoke('phonon', 'Hello.', ['voice' => 'Nobody'])->getResult();
    }

    #[Test]
    public function itRefusesAnAnswerThatIsNotAWavFile(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('ID3 not a wav'));
        $platform = new Platform([Factory::createProvider('http://ollama:11434', $httpClient)], new CatalogBasedModelRouter());

        $this->expectException(RuntimeException::class);

        $platform->invoke('phonon', 'Hello.', ['voice' => 'Hadley'])->getResult();
    }

    #[Test]
    public function itDoesNotClaimTheModelsOfOtherProviders(): void
    {
        static::assertFalse(Factory::createProvider('http://ollama:11434', new MockHttpClient())->supports('voxtral-mini-tts-latest'));
        static::assertTrue(Factory::createProvider('http://ollama:11434', new MockHttpClient())->supports('phonon:40m-multilingual'));
    }
}

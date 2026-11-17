<?php

declare(strict_types=1);

namespace App\Tests\AI\Platform;

use App\AI\Platform\Mistral\TextToSpeech\Factory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class MistralTextToSpeechTest extends TestCase
{
    private const string WAV = "RIFF\x24\x00\x00\x00WAVEfmt ";

    #[Test]
    public function itDecodesTheBase64AudioOfTheJsonAnswer(): void
    {
        $httpClient = new MockHttpClient(static function (string $method, string $url, array $options): JsonMockResponse {
            static::assertSame('https://api.mistral.ai/v1/audio/speech', $url);
            static::assertContains('Authorization: Bearer secret-key', $options['headers']);
            static::assertSame(['model' => 'voxtral-mini-tts-latest', 'input' => 'Bonjour.', 'voice_id' => 'voice_592', 'response_format' => 'wav'], json_decode($options['body'], true));

            return new JsonMockResponse(['audio_data' => base64_encode(self::WAV)]);
        });
        $platform = new Platform([Factory::createProvider('secret-key', $httpClient)], new CatalogBasedModelRouter());

        $result = $platform->invoke('voxtral-mini-tts-latest', 'Bonjour.', ['voice' => 'voice_592'])->getResult();

        static::assertInstanceOf(BinaryResult::class, $result);
        static::assertSame(self::WAV, $result->getContent());
    }

    #[Test]
    public function itAsksForAKeyBeforeCallingMistral(): void
    {
        $platform = new Platform([Factory::createProvider('', new MockHttpClient())], new CatalogBasedModelRouter());

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('MISTRAL_API_KEY');

        $platform->invoke('voxtral-mini-tts-latest', 'Hello.', ['voice' => 'voice_478'])->getResult();
    }

    #[Test]
    public function itReportsARefusedKey(): void
    {
        $httpClient = new MockHttpClient(new JsonMockResponse(['message' => 'Invalid API Key'], ['http_code' => 401]));
        $platform = new Platform([Factory::createProvider('wrong', $httpClient)], new CatalogBasedModelRouter());

        $this->expectException(AuthenticationException::class);

        $platform->invoke('voxtral-mini-tts-latest', 'Hello.', ['voice' => 'voice_478'])->getResult();
    }
}

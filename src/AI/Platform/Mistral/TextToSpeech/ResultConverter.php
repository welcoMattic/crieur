<?php

declare(strict_types=1);

namespace App\AI\Platform\Mistral\TextToSpeech;

use App\AI\Platform\Mistral\TextToSpeech;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\HttpStatusErrorHandlingTrait;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Mistral answers either the WAV file itself or a JSON document with the base64 field "audio_data".
 */
final class ResultConverter implements ResultConverterInterface
{
    use HttpStatusErrorHandlingTrait;

    public function supports(Model $model): bool
    {
        return $model instanceof TextToSpeech;
    }

    public function convert(RawResultInterface $result, array $options = []): BinaryResult
    {
        $response = $result->getObject();
        if (!$response instanceof ResponseInterface) {
            throw new RuntimeException('Voxtral results must come from an HTTP response.');
        }

        $this->throwOnHttpError($response);

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(\sprintf('Voxtral could not synthesize the speech (HTTP %d).', $response->getStatusCode()));
        }

        $content = $response->getContent();
        if (str_starts_with($content, 'RIFF')) {
            return new BinaryResult($content, 'audio/wav');
        }

        $data = json_decode($content, true);
        $audio = \is_array($data) && \is_string($data['audio_data'] ?? null) ? base64_decode($data['audio_data'], true) : false;
        if (false === $audio || !str_starts_with($audio, 'RIFF')) {
            throw new RuntimeException('Voxtral did not answer with a WAV file.');
        }

        return new BinaryResult($audio, 'audio/wav');
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
    }
}

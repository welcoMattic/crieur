<?php

declare(strict_types=1);

namespace App\AI\Platform\Ollama\TextToSpeech;

use App\AI\Platform\Ollama\TextToSpeech;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Result\HttpStatusErrorHandlingTrait;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

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
            throw new RuntimeException('Ollama speech results must come from an HTTP response.');
        }

        $this->throwOnHttpError($response);

        if (200 !== $response->getStatusCode()) {
            throw new RuntimeException(\sprintf('Ollama could not synthesize the speech (HTTP %d).', $response->getStatusCode()));
        }

        $content = $response->getContent();
        if (!str_starts_with($content, 'RIFF')) {
            throw new RuntimeException('Ollama did not answer with a WAV file.');
        }

        return new BinaryResult($content, 'audio/wav');
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
    }
}

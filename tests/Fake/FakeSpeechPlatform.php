<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Speech\Wav;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

/**
 * The speech platform of the test environment: every text becomes silence, 60 ms per character,
 * so that durations stay realistic and no test ever needs a voice engine or makes a sound.
 */
final class FakeSpeechPlatform
{
    public static function create(): Platform
    {
        return MockPlatformFactory::createPlatform(
            static fn (Model $model, array|string|object $payload): BinaryResult => new BinaryResult(
                Wav::silence(60 * mb_strlen(\is_string($payload) ? $payload : (string) json_encode($payload)))->toBytes(),
                'audio/wav',
            ),
        );
    }
}

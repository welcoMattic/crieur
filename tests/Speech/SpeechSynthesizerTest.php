<?php

declare(strict_types=1);

namespace App\Tests\Speech;

use App\Enum\VoiceGender;
use App\Speech\AudioPostProcessor;
use App\Speech\AudioStore;
use App\Speech\SpeechSynthesizer;
use App\Speech\Voice;
use App\Text\Language;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

final class SpeechSynthesizerTest extends TestCase
{
    #[Test]
    public function audioItCannotReadIsAFailureOfTheEngine(): void
    {
        $platform = MockPlatformFactory::createPlatform(static fn (): BinaryResult => new BinaryResult('<html>Rate limited</html>', 'text/html'));
        $directory = sys_get_temp_dir().'/crieur-synthesizer-'.bin2hex(random_bytes(4));
        $synthesizer = new SpeechSynthesizer($platform, new AudioStore($directory), new AudioPostProcessor());

        // A RuntimeException: the synthesis worker retries, then marks the article failed.
        $this->expectException(\RuntimeException::class);

        try {
            $synthesizer->synthesize('Hello.', new Voice('phonon', 'phonon:40m-multilingual', 'Hadley', 'Hadley', Language::English, VoiceGender::Female));
        } finally {
            static::assertDirectoryDoesNotExist($directory);
        }
    }
}

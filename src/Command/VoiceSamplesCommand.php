<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\VoiceGender;
use App\Speech\AudioStore;
use App\Speech\SpeechSynthesizer;
use App\Speech\VoiceCatalog;
use App\Text\Language;
use App\Text\TextNormalizer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'crieur:voices:sample', description: 'Writes the same sentences read by every voice into WAV files, to compare voices by ear')]
final readonly class VoiceSamplesCommand
{
    private const array SENTENCES = [
        'fr' => 'Le 17 novembre 2026, la Commission a publié un rapport de 42 pages. Selon M. Dupont, la hausse atteint 3,5 % sur un an.',
        'en' => 'On November 17, 2026, the committee published a 42-page report. According to Dr. Smith, prices rose by 3.5% in a year.',
    ];

    public function __construct(
        private VoiceCatalog $voices,
        private SpeechSynthesizer $synthesizer,
        private AudioStore $store,
        private TextNormalizer $normalizer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The directory that receives the WAV files')]
        string $directory,
        #[Option(description: 'Only this engine (phonon, voxtral)')]
        ?string $engine = null,
    ): int {
        $filesystem = new Filesystem();
        $failures = 0;
        foreach ($this->voices->engines() as $voiceEngine) {
            if (null !== $engine && $engine !== $voiceEngine->name) {
                continue;
            }
            foreach (Language::cases() as $language) {
                foreach (VoiceGender::cases() as $gender) {
                    $voice = $this->voices->voice($voiceEngine->name, $language, $gender);
                    $spoken = $this->normalizer->normalize(self::SENTENCES[$language->value], $language, $voiceEngine->spellsIntegers());
                    $target = \sprintf('%s/%s-%s-%s-%s.wav', rtrim($directory, '/'), $voiceEngine->name, $language->value, $gender->value, strtolower($voice->name));
                    try {
                        $audio = $this->synthesizer->synthesize($spoken, $voice);
                        $filesystem->dumpFile($target, $this->store->read($audio['key']));
                        $io->writeln(\sprintf('<info>%s</info> (%.1f s)', $target, $audio['durationMs'] / 1000));
                    } catch (\Throwable $e) {
                        ++$failures;
                        $io->writeln(\sprintf('<error>%s %s %s: %s</error>', $voiceEngine->name, $language->value, $gender->value, $e->getMessage()));
                    }
                }
            }
        }

        return 0 === $failures ? Command::SUCCESS : Command::FAILURE;
    }
}

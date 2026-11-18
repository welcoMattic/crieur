<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\LibraryReset;
use App\Demo\PublicDemo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'crieur:demo:reset', description: 'Empties the library of the public demo (APP_ENV=demo) now; its schedule does it every six hours')]
final readonly class DemoResetCommand
{
    public function __construct(
        private PublicDemo $demo,
        private LibraryReset $reset,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        // Anywhere else, this library is someone's own.
        if (!$this->demo->isEnabled()) {
            $io->error('Only the public demo (APP_ENV=demo) empties its library.');

            return Command::FAILURE;
        }

        $io->success(\sprintf('The library is empty: %d article(s) removed.', $this->reset->reset()));

        return Command::SUCCESS;
    }
}

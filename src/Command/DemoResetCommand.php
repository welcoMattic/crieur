<?php

declare(strict_types=1);

namespace App\Command;

use App\Demo\LibraryReset;
use App\Demo\PublicDemo;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'crieur:demo:reset', description: 'Empties the library of the public demo (APP_ENV=demo); with --at-next-reset, at its next reset time')]
final readonly class DemoResetCommand
{
    public function __construct(
        private PublicDemo $demo,
        private LibraryReset $reset,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Wait for the next reset time (every six hours from midnight UTC), then empty the library')]
        bool $atNextReset = false,
    ): int {
        // Anywhere else, this library is someone's own.
        if (!$this->demo->isEnabled()) {
            $io->error('Only the public demo (APP_ENV=demo) empties its library.');

            return Command::FAILURE;
        }

        if ($atNextReset) {
            $next = $this->demo->nextReset();
            $io->writeln(\sprintf('Next reset at %s.', $next->format('Y-m-d H:i T')));
            // At the reset time or just after it, never just before.
            sleep(max(0, $next->getTimestamp() - $this->clock->now()->getTimestamp()) + 1);
        }

        $io->success(\sprintf('%s: the library is empty, %d article(s) removed.', $this->clock->now()->format('Y-m-d H:i T'), $this->reset->reset()));

        return Command::SUCCESS;
    }
}

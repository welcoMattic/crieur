<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Demo\LibraryReset;
use App\Demo\PublicDemo;
use App\Message\ResetDemoLibrary;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ResetDemoLibraryHandler
{
    public function __construct(
        private PublicDemo $demo,
        private LibraryReset $reset,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ResetDemoLibrary $message): void
    {
        // Anywhere else, this library is someone's own.
        if (!$this->demo->isEnabled()) {
            $this->logger->warning('The library is emptied on the public demo only (APP_ENV=demo): nothing removed.');

            return;
        }

        $this->logger->notice('The library of the public demo is empty: {count} article(s) removed.', ['count' => $this->reset->reset()]);
    }
}

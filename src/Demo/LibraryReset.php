<?php

declare(strict_types=1);

namespace App\Demo;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Empties the library: every article, its chunks, its audio and episodes, and the messages the
 * workers have not handled yet. The settings stay.
 *
 * In SQL, as another process would: the workers notice that their article is gone.
 */
final readonly class LibraryReset
{
    public function __construct(
        private Connection $connection,
        #[Autowire(param: 'crieur.audio_dir')]
        private string $audioDirectory,
    ) {
    }

    /**
     * @return int how many articles were removed
     */
    public function reset(): int
    {
        $removed = $this->connection->transactional(function (Connection $connection): int {
            $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM article');
            $connection->executeStatement('DELETE FROM segment');
            $connection->executeStatement('DELETE FROM article');
            if ($connection->createSchemaManager()->tablesExist(['messenger_messages'])) {
                $connection->executeStatement('DELETE FROM messenger_messages');
            }

            return $count;
        });

        $filesystem = new Filesystem();
        $filesystem->remove($this->audioDirectory);
        $filesystem->mkdir($this->audioDirectory);

        return $removed;
    }
}

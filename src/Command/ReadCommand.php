<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\ArticleSource;
use App\Enum\ArticleStatus;
use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use App\Library\ArticleQueue;
use App\Library\InvalidArticleUrl;
use App\Repository\ArticleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsCommand(name: 'crieur:read', description: 'Adds a web article to the library and, with --wait, follows it until it is ready')]
final readonly class ReadCommand
{
    public function __construct(
        private ArticleQueue $queue,
        private ArticleRepository $articles,
        private EntityManagerInterface $entityManager,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The address of the article')]
        string $url,
        #[Option(description: 'auto, fr or en')]
        string $language = 'auto',
        #[Option(description: 'female or male')]
        string $voice = 'female',
        #[Option(description: 'Follow the article until it is ready (the queue workers must run)')]
        bool $wait = false,
    ): int {
        try {
            $article = $this->queue->queue(
                $url,
                ArticleSource::Cli,
                LanguageSetting::tryFrom($language) ?? LanguageSetting::Auto,
                VoiceGender::tryFrom($voice) ?? VoiceGender::Female,
            );
        } catch (InvalidArticleUrl $e) {
            $io->error(\sprintf('Crieur reads http and https pages only (%s).', $e->getMessage()));

            return Command::INVALID;
        }

        $io->writeln(\sprintf('Queued <info>%s</info>', $article->getUrl()));
        $listenUrl = $this->urls->generate('article_show', ['id' => $article->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL);

        if (!$wait) {
            $io->writeln(\sprintf('Listen at <href=%1$s>%1$s</>', $listenUrl));

            return Command::SUCCESS;
        }

        $id = $article->getId();
        $last = null;
        $deadline = time() + 900;
        while (time() < $deadline) {
            $this->entityManager->clear();
            $article = $this->articles->get($id);
            if (null === $article) {
                $io->error('The article was removed.');

                return Command::FAILURE;
            }

            $line = \sprintf('%-12s %s %d/%d chunks', $article->getStatus()->value, $article->getDisplayTitle(), $article->countReadySegments(), $article->getSegments()->count());
            if ($line !== $last) {
                $io->writeln($line);
                $last = $line;
            }

            if (ArticleStatus::Ready === $article->getStatus()) {
                $io->success(\sprintf('Ready: %s, %d words, %.0f s of audio, voice %s, edited by %s. Listen at %s', $article->getLanguage()?->value, $article->getWordCount(), $article->getAudioSeconds(), $article->getVoiceName(), $article->getEditor(), $listenUrl));

                return Command::SUCCESS;
            }

            if (ArticleStatus::Failed === $article->getStatus()) {
                $io->error(\sprintf('Failed: %s', $article->getFailure()));

                return Command::FAILURE;
            }

            usleep(500_000);
        }

        $io->warning('Still not ready after 15 minutes: are the queue workers running ("symfony serve" starts them)?');

        return Command::FAILURE;
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Attribute\McpPrompt;
use Mcp\Capability\Attribute\Schema;

/**
 * A ready-made request for the assistant: find good articles on a topic and queue them in Crieur.
 * In Claude Code it shows up as the /mcp__crieur__listen_later command.
 */
final readonly class ListenLaterPrompt
{
    /**
     * @return array{user: string}
     */
    #[McpPrompt(
        name: 'listen_later',
        title: 'Listen later',
        description: 'Find a few good articles on a topic and send them to Crieur, to listen to them later.',
    )]
    public function listenLater(
        #[Schema(description: 'What the articles should be about.')]
        string $topic,
        #[Schema(description: 'How many articles to send.')]
        string $count = '3',
        #[Schema(description: 'The language of the articles: fr or en.', enum: ['en', 'fr'])]
        string $language = 'en',
    ): array {
        return ['user' => \sprintf(
            'Find %d recent, substantial and well-written articles (not news briefs, not paywalled) in %s about: %s. '
            .'For each one, call the read_aloud tool of Crieur with its URL. Then list what you sent, with one sentence each on why it is worth listening to.',
            max(1, min(10, (int) $count)),
            'fr' === $language ? 'French' : 'English',
            $topic,
        )];
    }
}

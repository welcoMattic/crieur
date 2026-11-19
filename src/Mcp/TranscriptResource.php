<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Repository\ArticleRepository;
use App\Text\BlockKind;
use Mcp\Server\Capability\Attribute\McpResourceTemplate;
use Mcp\Server\Exception\ResourceNotFoundException;
use Symfony\Component\Uid\Uuid;

/**
 * The transcript of an article as Markdown, for an assistant that wants to quote or discuss it.
 */
final readonly class TranscriptResource
{
    public function __construct(
        private ArticleRepository $articles,
    ) {
    }

    #[McpResourceTemplate(
        uriTemplate: 'crieur://articles/{id}/transcript',
        name: 'article_transcript',
        title: 'Article transcript',
        description: 'The text of an article of the listening library, as Crieur reads it, in Markdown.',
        mimeType: 'text/markdown',
    )]
    public function transcript(string $id): string
    {
        $article = Uuid::isValid($id) ? $this->articles->get(Uuid::fromString($id)) : null;
        if (null === $article || [] === $article->getParagraphs(withRemoved: false)) {
            throw new ResourceNotFoundException(\sprintf('No transcript for the article "%s".', $id));
        }

        $lines = [];
        foreach ($article->getParagraphs(withRemoved: false) as $segments) {
            $text = implode(' ', array_map(static fn ($segment): string => $segment->getText(), $segments));
            $lines[] = match ($segments[0]->getKind()) {
                BlockKind::Title => '# '.$text,
                BlockKind::Heading => '## '.$text,
                BlockKind::Quote => '> '.$text,
                BlockKind::ListItem => '- '.$text,
                BlockKind::Paragraph => $text,
            };
        }

        $lines[] = \sprintf('Source: <%s>', $article->getUrl());

        return implode("\n\n", $lines)."\n";
    }
}

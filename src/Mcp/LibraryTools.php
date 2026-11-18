<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Demo\TooManyArticles;
use App\Entity\Article;
use App\Enum\ArticleSource;
use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use App\Library\ArticleQueue;
use App\Library\InvalidArticleUrl;
use App\Repository\ArticleRepository;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The MCP tools of Crieur: an AI assistant can hand articles to the ears of its user.
 */
final readonly class LibraryTools
{
    public function __construct(
        private ArticleQueue $queue,
        private ArticleRepository $articles,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'read_aloud',
        title: 'Read an article aloud',
        description: 'Adds a web article to the listening library of the user. Crieur fetches the page, extracts the article, prepares the text for the ear and reads it with a natural voice, in French or in English. Returns the id of the article and the address where the user can listen to it.',
        annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: true),
    )]
    public function readAloud(
        #[Schema(description: 'The address of the web page, http or https.', format: 'uri')]
        string $url,
        #[Schema(description: 'The language of the article, or "auto" to detect it.', enum: ['auto', 'fr', 'en'])]
        string $language = 'auto',
        #[Schema(description: 'The voice that reads the article.', enum: ['female', 'male'])]
        string $voice = 'female',
    ): array {
        try {
            $article = $this->queue->queue(
                $url,
                ArticleSource::Mcp,
                LanguageSetting::tryFrom($language) ?? LanguageSetting::Auto,
                VoiceGender::tryFrom($voice) ?? VoiceGender::Female,
            );
        } catch (InvalidArticleUrl) {
            throw new ToolCallException('Crieur reads http and https web pages only: check the address.');
        } catch (TooManyArticles) {
            throw new ToolCallException('The public demo of Crieur takes no more articles from this address for now: try again within the hour, or install Crieur to read without limits.');
        }

        return $this->describe($article) + [
            'message' => 'The article is queued. It becomes playable within seconds, and the user can listen to it in Crieur.',
        ];
    }

    /**
     * @return array{articles: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'list_library',
        title: 'List the listening library',
        description: 'Lists the most recent articles of the listening library, with their status (queued, extracting, preparing, synthesizing, ready, failed), language, duration and summary.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function listLibrary(
        #[Schema(description: 'How many articles to list, the most recent first.', minimum: 1, maximum: 50)]
        int $limit = 10,
    ): array {
        return ['articles' => array_map($this->describe(...), $this->articles->latest(max(1, min(50, $limit))))];
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_article',
        title: 'Get an article',
        description: 'Gives the status, the details and, on request, the transcript of one article of the listening library.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function getArticle(
        #[Schema(description: 'The id of the article, as read_aloud or list_library gave it.')]
        string $id,
        #[Schema(description: 'Include the full text of the article, paragraph by paragraph.')]
        bool $transcript = false,
    ): array {
        $article = Uuid::isValid($id) ? $this->articles->get(Uuid::fromString($id)) : null;
        if (null === $article) {
            throw new ToolCallException(\sprintf('No article with the id "%s" in the library.', $id));
        }

        $description = $this->describe($article);
        if ($transcript) {
            $description['transcript'] = array_values(array_map(
                static fn (array $segments): string => implode(' ', array_map(static fn ($segment): string => $segment->getText(), $segments)),
                $article->getParagraphs(withRemoved: false),
            ));
        }

        return $description;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Article $article): array
    {
        return [
            'id' => $article->getId()->toRfc4122(),
            'url' => $article->getUrl(),
            'status' => $article->getStatus()->value,
            'title' => $article->getTitle(),
            'site' => $article->getSiteName() ?? $article->getHost(),
            'language' => $article->getLanguage()?->value,
            'voice' => $article->getVoiceName(),
            'summary' => $article->getSummary(),
            'paragraphs' => $article->getParagraphCount(),
            'words' => $article->getWordCount(),
            'audio_seconds' => round($article->getAudioSeconds(), 1),
            'failure' => $article->getFailure(),
            'listen_url' => $this->urls->generate('article_show', ['id' => $article->getId()->toRfc4122()], UrlGeneratorInterface::ABSOLUTE_URL),
            'created_at' => $article->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}

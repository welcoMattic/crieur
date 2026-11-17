<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ArticleSource;
use App\Enum\ArticleStatus;
use App\Enum\LanguageSetting;
use App\Enum\VoiceGender;
use App\Repository\ArticleRepository;
use App\Text\Language;
use App\Text\PreparedArticle;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A web article in the library, from its URL to its spoken chunks.
 */
#[ORM\Entity(repositoryClass: ArticleRepository::class)]
#[ORM\Index(name: 'article_created_at', columns: ['created_at'])]
class Article
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(enumType: ArticleStatus::class, length: 16)]
    private ArticleStatus $status = ArticleStatus::Queued;

    /**
     * A translation key in the "errors" domain, set when the status is Failed.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $failure = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $siteName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $author = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $excerpt = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $imageUrl = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(enumType: Language::class, length: 2, nullable: true)]
    private ?Language $language = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $voiceName = null;

    /**
     * The voice engine that reads this article ("phonon", "voxtral").
     */
    #[ORM\Column(length: 32)]
    private string $voiceEngine;

    /**
     * Who wrote the spoken text: "rules" for the deterministic preparation, else the model name.
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $editor = null;

    /**
     * A one-sentence summary written by the speech editor, when one is enabled.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column]
    private int $wordCount = 0;

    #[ORM\Column]
    private int $paragraphCount = 0;

    /**
     * Incremented when the voice or the language changes: a synthesis run started for an older
     * revision stops, and the next one reads the remaining chunks again.
     */
    #[ORM\Column]
    private int $revision = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readyAt = null;

    /**
     * @var Collection<int, Segment>
     */
    #[ORM\OneToMany(targetEntity: Segment::class, mappedBy: 'article', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => \SortDirection::Ascending])]
    private Collection $segments;

    public function __construct(
        #[ORM\Column(length: 2048)]
        private string $url,
        #[ORM\Column(enumType: LanguageSetting::class, length: 4)]
        private LanguageSetting $languageSetting,
        #[ORM\Column(enumType: VoiceGender::class, length: 8)]
        private VoiceGender $voiceGender,
        string $voiceEngine,
        #[ORM\Column(enumType: ArticleSource::class, length: 16)]
        private ArticleSource $source,
        ?\DateTimeImmutable $now = null,
    ) {
        $this->id = Uuid::v7();
        $this->voiceEngine = $voiceEngine;
        $this->segments = new ArrayCollection();
        $this->createdAt = $now ?? new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getHost(): string
    {
        $host = parse_url($this->url, \PHP_URL_HOST);

        return \is_string($host) ? preg_replace('/^www\./', '', $host) ?? $host : $this->url;
    }

    public function getStatus(): ArticleStatus
    {
        return $this->status;
    }

    public function getFailure(): ?string
    {
        return $this->failure;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getDisplayTitle(): string
    {
        return $this->title ?? $this->getHost();
    }

    public function getSiteName(): ?string
    {
        return $this->siteName;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function getExcerpt(): ?string
    {
        return $this->excerpt;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getLanguageSetting(): LanguageSetting
    {
        return $this->languageSetting;
    }

    public function getLanguage(): ?Language
    {
        return $this->language;
    }

    public function getVoiceGender(): VoiceGender
    {
        return $this->voiceGender;
    }

    public function getVoiceName(): ?string
    {
        return $this->voiceName;
    }

    public function getVoiceEngine(): string
    {
        return $this->voiceEngine;
    }

    public function getEditor(): ?string
    {
        return $this->editor;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getWordCount(): int
    {
        return $this->wordCount;
    }

    public function getParagraphCount(): int
    {
        return $this->paragraphCount;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getSource(): ArticleSource
    {
        return $this->source;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getReadyAt(): ?\DateTimeImmutable
    {
        return $this->readyAt;
    }

    /**
     * @return Collection<int, Segment>
     */
    public function getSegments(): Collection
    {
        return $this->segments;
    }

    /**
     * @return array<int, list<Segment>> segments grouped by paragraph, in reading order
     */
    public function getParagraphs(): array
    {
        $paragraphs = [];
        foreach ($this->segments as $segment) {
            $paragraphs[$segment->getParagraph()][] = $segment;
        }

        return $paragraphs;
    }

    public function countReadySegments(): int
    {
        return $this->segments->filter(static fn (Segment $segment): bool => $segment->isReady())->count();
    }

    /**
     * Seconds of audio already synthesized, pauses included.
     */
    public function getAudioSeconds(): float
    {
        $milliseconds = 0;
        foreach ($this->segments as $segment) {
            if ($segment->isReady()) {
                $milliseconds += ($segment->getDurationMs() ?? 0) + $segment->getPauseAfterMs();
            }
        }

        return $milliseconds / 1000;
    }

    public function startExtraction(?\DateTimeImmutable $now = null): void
    {
        $this->status = ArticleStatus::Extracting;
        $this->failure = null;
        $this->touch($now);
    }

    public function describe(
        string $title,
        ?string $siteName,
        ?string $author,
        ?string $excerpt,
        ?string $imageUrl,
        ?\DateTimeImmutable $publishedAt,
        ?\DateTimeImmutable $now = null,
    ): void {
        $this->title = mb_substr($title, 0, 512);
        $this->siteName = null === $siteName ? null : mb_substr($siteName, 0, 255);
        $this->author = null === $author ? null : mb_substr($author, 0, 255);
        $this->excerpt = $excerpt;
        $this->imageUrl = null !== $imageUrl && \strlen($imageUrl) <= 2048 ? $imageUrl : null;
        $this->publishedAt = $publishedAt;
        $this->status = ArticleStatus::Preparing;
        $this->touch($now);
    }

    /**
     * Replaces the chunks with a new preparation of the text, for the given voice.
     */
    public function prepare(PreparedArticle $prepared, string $voiceName, string $editor, ?\DateTimeImmutable $now = null): void
    {
        foreach ($this->segments as $segment) {
            $this->segments->removeElement($segment);
        }

        foreach ($prepared->segments as $position => $preparedSegment) {
            $this->segments->add(new Segment($this, $position, $preparedSegment));
        }

        $this->language = $prepared->language;
        $this->wordCount = $prepared->wordCount;
        $this->paragraphCount = $prepared->paragraphCount;
        $this->voiceName = $voiceName;
        $this->editor = $editor;
        $this->status = ArticleStatus::Synthesizing;
        ++$this->revision;
        $this->touch($now);
    }

    public function edit(string $editor, ?string $summary, ?\DateTimeImmutable $now = null): void
    {
        $this->editor = $editor;
        if (null !== $summary && '' !== trim($summary)) {
            $this->summary = trim($summary);
        }
        $this->touch($now);
    }

    /**
     * Another voice for the chunks not heard yet: their audio is dropped and synthesized again.
     */
    public function changeVoice(VoiceGender $gender, string $voiceName, string $voiceEngine, ?\DateTimeImmutable $now = null): void
    {
        $this->voiceGender = $gender;
        $this->voiceName = $voiceName;
        $this->voiceEngine = $voiceEngine;
        foreach ($this->segments as $segment) {
            $segment->forgetAudio();
        }
        if (ArticleStatus::Ready === $this->status) {
            $this->status = ArticleStatus::Synthesizing;
        }
        ++$this->revision;
        $this->touch($now);
    }

    public function changeLanguageSetting(LanguageSetting $setting, ?\DateTimeImmutable $now = null): void
    {
        $this->languageSetting = $setting;
        $this->touch($now);
    }

    /**
     * A ready article gets chunks to synthesize again (the editor rewrote some): it is in progress
     * until they have their audio.
     */
    public function resumeSynthesis(?\DateTimeImmutable $now = null): void
    {
        if (ArticleStatus::Ready === $this->status) {
            $this->status = ArticleStatus::Synthesizing;
            $this->touch($now);
        }
    }

    public function markReady(?\DateTimeImmutable $now = null): void
    {
        $this->status = ArticleStatus::Ready;
        $this->readyAt = $now ?? new \DateTimeImmutable();
        $this->touch($now);
    }

    public function fail(string $failure, ?\DateTimeImmutable $now = null): void
    {
        $this->status = ArticleStatus::Failed;
        $this->failure = $failure;
        $this->touch($now);
    }

    private function touch(?\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now ?? new \DateTimeImmutable();
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Text\BlockKind;
use App\Text\PreparedSegment;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One chunk of an article: a sentence, or a part of a long one, with its audio once synthesized.
 */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'segment_position', columns: ['article_id', 'position'])]
class Segment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $paragraph;

    #[ORM\Column(enumType: BlockKind::class, length: 16)]
    private BlockKind $kind;

    /**
     * The text as the article shows it, for the read-along view.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    /**
     * The text written for the ear, as the voice engine receives it.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $spoken;

    /**
     * True once the speech editor (LLM) rewrote the spoken text.
     */
    #[ORM\Column]
    private bool $edited = false;

    #[ORM\Column]
    private int $pauseAfterMs;

    /**
     * The key of the audio file in the audio store.
     */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $audioKey = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /**
     * Taken out of the reading by the listener (a line in another language, a leftover of the
     * page): it stays in the transcript, struck through, and the voice skips it.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $removed = false;

    public function __construct(
        #[ORM\ManyToOne(inversedBy: 'segments')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Article $article,
        #[ORM\Column]
        private int $position,
        PreparedSegment $prepared,
    ) {
        $this->paragraph = $prepared->paragraph;
        $this->kind = $prepared->kind;
        $this->text = $prepared->text;
        $this->spoken = $prepared->spoken;
        $this->pauseAfterMs = $prepared->pauseAfterMs;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getArticle(): Article
    {
        return $this->article;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getParagraph(): int
    {
        return $this->paragraph;
    }

    public function getKind(): BlockKind
    {
        return $this->kind;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getSpoken(): string
    {
        return $this->spoken;
    }

    public function isEdited(): bool
    {
        return $this->edited;
    }

    public function getPauseAfterMs(): int
    {
        return $this->pauseAfterMs;
    }

    public function getAudioKey(): ?string
    {
        return $this->audioKey;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function isReady(): bool
    {
        return null !== $this->audioKey;
    }

    public function isRemoved(): bool
    {
        return $this->removed;
    }

    /**
     * Out of the reading: the audio becomes the given (short) silence, so the chunk keeps its
     * place in the timeline and the article stays complete.
     */
    public function remove(string $silenceKey, int $durationMs): void
    {
        $this->removed = true;
        $this->attachAudio($silenceKey, $durationMs);
    }

    /**
     * Back in the reading: its audio is synthesized again.
     */
    public function restore(): void
    {
        $this->removed = false;
        $this->forgetAudio();
    }

    /**
     * The speech editor rewrote this chunk for the ear. Audio made from the former text is dropped.
     */
    public function rewrite(string $spoken): void
    {
        $this->edited = true;
        if ($spoken !== $this->spoken) {
            $this->spoken = $spoken;
            // A removed chunk keeps its silence: restoring it synthesizes the new text.
            if (!$this->removed) {
                $this->forgetAudio();
            }
        }
    }

    public function attachAudio(string $audioKey, int $durationMs): void
    {
        $this->audioKey = $audioKey;
        $this->durationMs = $durationMs;
    }

    public function forgetAudio(): void
    {
        $this->audioKey = null;
        $this->durationMs = null;
    }
}

<?php

declare(strict_types=1);

namespace App\Settings;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The voice engine and the speech editor in use. The settings page can change them at any time;
 * until it does, CRIEUR_VOICE_ENGINE and CRIEUR_EDITOR give them.
 *
 * Values are read from the database at each use, without cache: the queue workers run for an
 * hour and must follow a change made in the browser at once.
 */
final readonly class EngineSettings
{
    public const string VOICE_ENGINE = 'voice_engine';
    public const string EDITOR = 'editor';
    public const array EDITORS = ['auto', 'mistral', 'dmr', 'off'];

    /**
     * @param array<string, mixed> $engines the "crieur.voice_engines" parameter, by engine name
     */
    public function __construct(
        private Connection $connection,
        #[Autowire(param: 'crieur.voice_engines')]
        private array $engines,
        #[Autowire(param: 'crieur.voice_engine')]
        private string $defaultVoiceEngine,
        #[Autowire(param: 'crieur.editor')]
        private string $defaultEditor,
    ) {
    }

    public function voiceEngine(): string
    {
        $value = $this->read(self::VOICE_ENGINE);

        return null !== $value && \array_key_exists($value, $this->engines) ? $value : $this->defaultVoiceEngine;
    }

    public function editor(): string
    {
        $value = $this->read(self::EDITOR);
        // "ollama", the local editor of earlier versions, is now Docker Model Runner.
        $value = 'ollama' === $value ? 'dmr' : $value;
        $default = 'ollama' === $this->defaultEditor ? 'dmr' : $this->defaultEditor;

        return null !== $value && \in_array($value, self::EDITORS, true) ? $value : $default;
    }

    /**
     * @throws \InvalidArgumentException for an engine that config/packages/crieur.yaml does not define
     */
    public function changeVoiceEngine(string $engine): void
    {
        if (!\array_key_exists($engine, $this->engines)) {
            throw new \InvalidArgumentException(\sprintf('Unknown voice engine "%s".', $engine));
        }

        $this->write(self::VOICE_ENGINE, $engine);
    }

    /**
     * @throws \InvalidArgumentException for another value than auto, mistral, dmr or off
     */
    public function changeEditor(string $editor): void
    {
        if (!\in_array($editor, self::EDITORS, true)) {
            throw new \InvalidArgumentException(\sprintf('Unknown speech editor "%s".', $editor));
        }

        $this->write(self::EDITOR, $editor);
    }

    private function read(string $name): ?string
    {
        try {
            $value = $this->connection->fetchOne('SELECT value FROM setting WHERE name = ?', [$name]);
        } catch (DbalException) {
            // No schema yet (before the first migration): the environment decides.
            return null;
        }

        return \is_string($value) ? $value : null;
    }

    private function write(string $name, string $value): void
    {
        $this->connection->transactional(function (Connection $connection) use ($name, $value): void {
            if (0 === $connection->update('setting', ['value' => $value], ['name' => $name])) {
                $connection->insert('setting', ['name' => $name, 'value' => $value]);
            }
        });
    }
}

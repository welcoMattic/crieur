<?php

declare(strict_types=1);

namespace App\Speech;

use App\Enum\VoiceGender;
use App\Settings\EngineSettings;
use App\Text\Language;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The voices of each engine, read from the "crieur.voice_engines" parameter.
 */
final readonly class VoiceCatalog
{
    /**
     * @param array<string, array{label: string, model: string, license: string, local: bool, voices: array<string, array<string, array{id: string, name: string}>>}> $engines
     */
    public function __construct(
        #[Autowire(param: 'crieur.voice_engines')]
        private array $engines,
        #[Autowire(param: 'crieur.voice_engine')]
        private string $defaultEngine,
        private ?EngineSettings $settings = null,
    ) {
    }

    /**
     * The engine of new articles: the one chosen on the settings page, else CRIEUR_VOICE_ENGINE.
     */
    public function defaultEngine(): VoiceEngine
    {
        return $this->engine($this->settings?->voiceEngine() ?? $this->defaultEngine);
    }

    public function engine(string $name): VoiceEngine
    {
        $config = $this->engines[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown voice engine "%s", expected one of "%s".', $name, implode('", "', array_keys($this->engines))));
        if ('' === $config['model']) {
            throw new \InvalidArgumentException(\sprintf('The voice engine "%s" has no model.', $name));
        }

        return new VoiceEngine($name, $config['label'], $config['model'], $config['license'], $config['local']);
    }

    /**
     * @return list<VoiceEngine>
     */
    public function engines(): array
    {
        return array_map($this->engine(...), array_keys($this->engines));
    }

    public function voice(string $engineName, Language $language, VoiceGender $gender): Voice
    {
        $engine = $this->engine($engineName);
        $voice = $this->engines[$engineName]['voices'][$language->value][$gender->value]
            ?? throw new \InvalidArgumentException(\sprintf('The voice engine "%s" has no %s voice in "%s".', $engineName, $gender->value, $language->value));

        return new Voice($engineName, $engine->model, $voice['id'], $voice['name'], $language, $gender);
    }
}

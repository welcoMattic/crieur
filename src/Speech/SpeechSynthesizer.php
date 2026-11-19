<?php

declare(strict_types=1);

namespace App\Speech;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\BinaryResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Turns one chunk of spoken text into audio, through the speech platform of Symfony AI.
 *
 * The platform routes on the model name: "phonon:..." goes to Ollama, "voxtral-..." to Mistral.
 * The audio is cached: the same text read by the same voice is synthesized once.
 */
final readonly class SpeechSynthesizer
{
    public function __construct(
        #[Autowire(service: 'crieur.platform.speech')]
        private PlatformInterface $platform,
        private AudioStore $store,
        private AudioPostProcessor $postProcessor,
    ) {
    }

    /**
     * @return array{key: string, durationMs: int}
     */
    public function synthesize(string $spoken, Voice $voice): array
    {
        $key = AudioStore::key($voice, $spoken);
        if ($this->store->has($key)) {
            return ['key' => $key, 'durationMs' => Wav::fromBytes($this->store->read($key))->durationMs()];
        }

        $options = ['voice' => $voice->id, 'response_format' => 'wav'];
        if (str_starts_with($voice->model, 'phonon')) {
            // Picks the Phonon server of the language; the bridges send every other option to the API.
            $options['language'] = $voice->language->value;
        }

        $result = $this->platform->invoke($voice->model, $spoken, $options)->getResult();
        if (!$result instanceof BinaryResult) {
            throw new \UnexpectedValueException(\sprintf('The speech platform answered "%s" instead of audio.', $result::class));
        }

        try {
            $wav = Wav::fromBytes($result->getContent());
        } catch (\InvalidArgumentException $e) {
            throw new \UnexpectedValueException(\sprintf('The speech platform answered audio Crieur cannot read: %s', $e->getMessage()), 0, $e);
        }
        $wav = $this->postProcessor->process($wav);
        $this->store->write($key, $wav->toBytes());

        return ['key' => $key, 'durationMs' => $wav->durationMs()];
    }

    /**
     * A silence in the format of the voice, for a chunk with nothing to say.
     *
     * @return array{key: string, durationMs: int}
     */
    public function silence(int $milliseconds, Voice $voice): array
    {
        $key = AudioStore::key($voice, \sprintf('<silence %d ms>', $milliseconds));
        $wav = Wav::silence($milliseconds);
        if (!$this->store->has($key)) {
            $this->store->write($key, $wav->toBytes());
        }

        return ['key' => $key, 'durationMs' => $wav->durationMs()];
    }
}

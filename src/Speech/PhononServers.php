<?php

declare(strict_types=1);

namespace App\Speech;

/**
 * Phonon servers of Gradium (ptts-openai-server), one per language, for hosts without Ollama:
 * Ollama serves Phonon on Apple Silicon only. CRIEUR_PHONON_SERVERS lists them as
 * "fr=http://127.0.0.1:8880,en=http://127.0.0.1:8881"; empty, Phonon goes through Ollama.
 */
final readonly class PhononServers
{
    /**
     * @param array<string, string> $servers base URL by language code
     */
    private function __construct(
        public array $servers,
    ) {
    }

    public static function fromString(string $value): self
    {
        $servers = [];
        foreach (explode(',', $value) as $entry) {
            if (1 === preg_match('~^\s*([a-z]{2})\s*=\s*(https?://\S+?)/*\s*$~', $entry, $match)) {
                $servers[$match[1]] = $match[2];
            }
        }

        return new self($servers);
    }

    public function isEmpty(): bool
    {
        return [] === $this->servers;
    }

    public function for(string $language): ?string
    {
        return $this->servers[$language] ?? null;
    }

    /**
     * The addresses without credentials, should they hold any, for the settings page.
     *
     * @return array<string, string>
     */
    public function displayed(): array
    {
        return array_map(static function (string $url): string {
            $parts = parse_url($url);
            if (false === $parts || !isset($parts['host'])) {
                return $url;
            }

            return \sprintf('%s://%s%s', $parts['scheme'] ?? 'http', $parts['host'], isset($parts['port']) ? ':'.$parts['port'] : '');
        }, $this->servers);
    }
}

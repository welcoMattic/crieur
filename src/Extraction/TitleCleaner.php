<?php

declare(strict_types=1);

namespace App\Extraction;

/**
 * The title without the name of the site that pages append to it: "Town crier - Wikipedia",
 * "Crieur public — Wikipédia", "Title | Site", "Title (Symfony Blog)". The suffix goes only when it
 * is the site name (or starts with it), or a label of the address.
 */
final class TitleCleaner
{
    public static function clean(string $title, ?string $siteName, string $url): string
    {
        $title = trim($title);
        if (1 !== preg_match('/^(.+?)(?:\s+[-–—|·:]\s+([^-–—|·:]{2,60})|\s+\(([^()]{2,60})\))$/u', $title, $match)) {
            return $title;
        }
        $suffix = self::plain($match[2] ?? '') ?: self::plain($match[3] ?? '');
        $site = self::plain($siteName ?? '');
        $host = parse_url($url, \PHP_URL_HOST);
        $labels = \is_string($host) ? explode('.', preg_replace('/^www\./', '', $host) ?? $host) : [];
        $names = array_filter([$site, ...array_map(self::plain(...), \array_slice($labels, 0, -1)), self::plain(implode('.', $labels))]);

        foreach ($names as $name) {
            if ($suffix === $name || (mb_strlen($name) >= 4 && str_starts_with($suffix, $name))) {
                return trim($match[1]);
            }
        }

        return $title;
    }

    private static function plain(string $text): string
    {
        $ascii = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);

        return preg_replace('/[^a-z0-9]/', '', \is_string($ascii) ? $ascii : strtolower($text)) ?? '';
    }
}

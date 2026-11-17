<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * The icons of the interface, drawn on a 24 px grid: filled shapes for the transport,
 * 1.75 px rounded strokes for the rest. They take the colour of the text (currentColor).
 */
final class Icons
{
    private const string FILLED = 'fill="currentColor" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"';

    private const array SHAPES = [
        'play' => '<path d="M8 5.5v13L18.5 12z" '.self::FILLED.'/>',
        'pause' => '<rect x="6.5" y="5" width="4" height="14" rx="1.2" fill="currentColor"/><rect x="13.5" y="5" width="4" height="14" rx="1.2" fill="currentColor"/>',
        'previous' => '<rect x="5" y="5.5" width="2.5" height="13" rx="1.1" fill="currentColor"/><path d="M18.5 6.25v11.5L10 12z" '.self::FILLED.'/>',
        'next' => '<path d="M5.5 6.25v11.5L14 12z" '.self::FILLED.'/><rect x="16.5" y="5.5" width="2.5" height="13" rx="1.1" fill="currentColor"/>',
        'stop' => '<rect x="6.5" y="6.5" width="11" height="11" rx="2" fill="currentColor"/>',
        'speed' => '<path d="M4.6 16.8a8.2 8.2 0 1 1 14.8 0"/><path d="M12 13.6l3.6-4.4"/><circle cx="12" cy="14" r="1.3" fill="currentColor" stroke="none"/>',
        'voice' => '<circle cx="9" cy="8.5" r="3.5"/><path d="M3 19.5c.6-3.1 3-5 6-5s5.4 1.9 6 5"/><path d="M17.2 6.6c1.1 1.4 1.1 3.4 0 4.8"/><path d="M19.8 4.6c2.1 2.6 2.1 6.2 0 8.8"/>',
        'language' => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17"/><path d="M12 3.5c2.3 2.4 3.5 5.2 3.5 8.5s-1.2 6.1-3.5 8.5c-2.3-2.4-3.5-5.2-3.5-8.5s1.2-6.1 3.5-8.5z"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3A4 4 0 0 0 13 5.3l-1.2 1.2"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1.2-1.2"/>',
        'copy' => '<rect x="8.5" y="8.5" width="11" height="11" rx="2.2"/><path d="M15.5 8.5V6.7a2.2 2.2 0 0 0-2.2-2.2H6.7a2.2 2.2 0 0 0-2.2 2.2v6.6a2.2 2.2 0 0 0 2.2 2.2h1.8"/>',
        'trash' => '<path d="M4.5 7h15"/><path d="M9.5 7V5.3c0-.7.6-1.3 1.3-1.3h2.4c.7 0 1.3.6 1.3 1.3V7"/><path d="M6.5 7l.8 11.2a2 2 0 0 0 2 1.8h5.4a2 2 0 0 0 2-1.8L17.5 7"/><path d="M10.2 11v5"/><path d="M13.8 11v5"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="8.5"/><path d="M8.2 12.3l2.6 2.6 5-5.2"/>',
        'alert' => '<path d="M10.3 4.6L3.2 17a2 2 0 0 0 1.7 3h14.2a2 2 0 0 0 1.7-3L13.7 4.6a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4"/><circle cx="12" cy="16.7" r="1" fill="currentColor" stroke="none"/>',
        'wave' => '<path d="M4 10v4"/><path d="M8 7v10"/><path d="M12 4v16"/><path d="M16 8v8"/><path d="M20 10.5v3"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'progress' => '<circle cx="12" cy="12" r="8.5" opacity=".25"/><path d="M12 3.5a8.5 8.5 0 0 1 8.5 8.5"/>',
        'external' => '<path d="M14 4.5h5.5V10"/><path d="M19.5 4.5L11 13"/><path d="M17.5 13.5v4a2 2 0 0 1-2 2h-9a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2h4"/>',
        'download' => '<path d="M12 4.5v10"/><path d="M7.5 10.5L12 15l4.5-4.5"/><path d="M5 19.5h14"/>',
        'retry' => '<path d="M4.6 12a7.4 7.4 0 1 0 2.2-5.3"/><path d="M4.6 4.4v4h4"/>',
        'arrow-left' => '<path d="M19 12H5"/><path d="M11 6l-6 6 6 6"/>',
        'arrow-right' => '<path d="M5 12h14"/><path d="M13 6l6 6-6 6"/>',
        'settings' => '<path d="M4 7h9"/><path d="M17 7h3"/><circle cx="15" cy="7" r="2"/><path d="M4 17h3"/><path d="M11 17h9"/><circle cx="9" cy="17" r="2"/>',
        'assistant' => '<path d="M9 3.5v4"/><path d="M15 3.5v4"/><path d="M6.5 7.5h11v3a5.5 5.5 0 0 1-11 0z"/><path d="M12 16v4.5"/>',
        'bookmark' => '<path d="M7 4.5h10a1 1 0 0 1 1 1v14l-6-4-6 4v-14a1 1 0 0 1 1-1z"/>',
        'feed' => '<path d="M5.5 4.5a14 14 0 0 1 14 14"/><path d="M5.5 10.5a8 8 0 0 1 8 8"/><circle cx="6.5" cy="17.5" r="1.6" fill="currentColor" stroke="none"/>',
        'eye' => '<path d="M2.8 12s3.4-6.5 9.2-6.5 9.2 6.5 9.2 6.5-3.4 6.5-9.2 6.5S2.8 12 2.8 12z"/><circle cx="12" cy="12" r="2.8"/>',
        'sparkle' => '<path d="M11 4l1.7 4.6L17.3 10.3l-4.6 1.7L11 16.6l-1.7-4.6L4.7 10.3l4.6-1.7z"/><path d="M18 14.5l.8 2 2 .8-2 .8-.8 2-.8-2-2-.8 2-.8z"/>',
        'close' => '<path d="M6.5 6.5l11 11"/><path d="M17.5 6.5l-11 11"/>',
        'text' => '<path d="M5 6.5h14"/><path d="M5 10.5h14"/><path d="M5 14.5h14"/><path d="M5 18.5h8"/>',
        'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
    ];

    /**
     * @param ?string $label an accessible name, for an icon that is not next to a text saying the same
     */
    #[AsTwigFunction('icon', isSafe: ['html'])]
    public function icon(string $name, ?string $label = null, string $class = ''): string
    {
        $shape = self::SHAPES[$name] ?? throw new \InvalidArgumentException(\sprintf('Unknown icon "%s".', $name));
        $accessibility = null === $label
            ? 'aria-hidden="true"'
            : \sprintf('role="img" aria-label="%s"', htmlspecialchars($label, \ENT_QUOTES | \ENT_HTML5));

        return \sprintf(
            '<svg class="icon icon-%s%s" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" focusable="false" %s>%s</svg>',
            $name,
            '' === $class ? '' : ' '.htmlspecialchars($class, \ENT_QUOTES | \ENT_HTML5),
            $accessibility,
            $shape,
        );
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::SHAPES);
    }
}

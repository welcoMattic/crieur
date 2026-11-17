<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * The mark of Crieur: a voice waveform in white on a deep blue rounded square, as the icon of the
 * macOS app. One drawing for the header, the favicon, the app icons and the podcast cover
 * (app:dev:icons rasterizes this very SVG). Small sizes get fewer, thicker bars.
 */
final class BrandMark
{
    public const string BLUE = '#1f4f99';
    public const int VIEWBOX = 512;

    /**
     * Bar heights as a part of the side, the width of a bar and the gap between two bars.
     */
    private const array GLYPHS = [
        'tiny' => ['heights' => [0.375, 0.75, 0.5], 'bar' => 0.125, 'gap' => 0.125],
        'small' => ['heights' => [0.28, 0.54, 0.72, 0.42], 'bar' => 0.115, 'gap' => 0.075],
        'medium' => ['heights' => [0.24, 0.46, 0.66, 0.52, 0.30], 'bar' => 0.085, 'gap' => 0.06],
        'full' => ['heights' => [0.18, 0.38, 0.62, 0.44, 0.56, 0.30, 0.16], 'bar' => 0.064, 'gap' => 0.04],
    ];

    /**
     * @param 'tiny'|'small'|'medium'|'full' $glyph
     * @param float                          $scale  the size of the waveform, 1 for the icon; less for a full-bleed square (maskable icon, cover)
     * @param float                          $offset vertical shift of the waveform, as a part of the side
     * @param ?int                           $grid   the size in pixels to align the bars on, for crisp favicons
     */
    public static function svg(string $glyph = 'full', bool $rounded = true, float $scale = 1.0, float $offset = 0.0, ?int $size = null, string $attributes = '', ?int $grid = null): string
    {
        $side = self::VIEWBOX;
        $spec = self::GLYPHS[$glyph];
        // On a pixel grid, every length is a whole number of pixels, and heights keep the parity of the side.
        $pixel = null === $grid ? null : $side / $grid;
        $snap = static fn (float $length, int $multiple = 1): float => null === $pixel ? $length : max($multiple, round($length / $pixel / $multiple) * $multiple) * $pixel;
        $bar = $snap($spec['bar'] * $side * $scale);
        $gap = $snap($spec['gap'] * $side * $scale);
        $count = \count($spec['heights']);
        $x = $snap(($side - ($count * $bar + ($count - 1) * $gap)) / 2);
        $middle = $side / 2 + $offset * $side;

        $bars = '';
        foreach ($spec['heights'] as $height) {
            $h = $snap(max($bar, $height * $side * $scale), 2);
            $radius = null === $pixel ? $bar / 2 : min($bar / 2, $pixel / 2);
            $bars .= \sprintf('<rect x="%s" y="%s" width="%s" height="%s" rx="%s"/>', self::n($x), self::n($middle - $h / 2), self::n($bar), self::n($h), self::n($radius));
            $x += $bar + $gap;
        }

        $dimensions = null === $size ? '' : \sprintf(' width="%d" height="%d"', $size, $size);

        return \sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d"%2$s%3$s><rect width="%1$d" height="%1$d"%4$s fill="%5$s"/><g fill="#ffffff">%6$s</g></svg>',
            $side,
            $dimensions,
            '' === $attributes ? '' : ' '.$attributes,
            $rounded ? ' rx="'.self::n($side * 0.225).'"' : '',
            self::BLUE,
            $bars,
        );
    }

    /**
     * @param 'tiny'|'small'|'medium'|'full' $glyph
     */
    #[AsTwigFunction('brand_mark', isSafe: ['html'])]
    public function mark(int $size = 28, string $glyph = 'small'): string
    {
        return self::svg($glyph, size: $size, attributes: 'class="mark" aria-hidden="true" focusable="false"');
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}

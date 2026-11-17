<?php

declare(strict_types=1);

namespace App\Command\Dev;

use App\Twig\BrandMark;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Writes the favicon, the app icons and the podcast cover in public/ from the SVG of BrandMark,
 * the one the header shows. Needs the Imagick extension; the cover wordmark uses a serif font of
 * the system (New York on macOS, else Georgia or DejaVu Serif).
 */
#[When(env: 'dev')]
#[AsCommand(name: 'app:dev:icons', description: 'Generates the favicon, app icons and podcast cover from the SVG mark')]
final readonly class IconsCommand
{
    private const array SERIF_FONTS = [
        '/System/Library/Fonts/NewYork.ttf',
        '/System/Library/Fonts/Supplemental/Georgia.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf',
    ];

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        if (!\extension_loaded('imagick')) {
            $io->error('The Imagick extension is needed to rasterize the SVG mark.');

            return 1;
        }

        $public = $this->projectDir.'/public';
        $filesystem = new Filesystem();

        $favicon = BrandMark::svg('small', grid: 32);
        $icon = BrandMark::svg('full');
        $maskable = BrandMark::svg('full', rounded: false, scale: 0.72);
        $filesystem->dumpFile($public.'/favicon.svg', $favicon."\n");
        $filesystem->dumpFile($public.'/icons/icon.svg', $icon."\n");

        $written = ['favicon.svg', 'icons/icon.svg'];
        foreach ([[$icon, 192, 'icons/icon-192.png'], [$icon, 512, 'icons/icon-512.png'], [$maskable, 512, 'icons/icon-maskable-512.png'], [BrandMark::svg('medium', rounded: false, scale: 0.8), 180, 'apple-touch-icon.png']] as [$svg, $size, $path]) {
            $filesystem->dumpFile($public.'/'.$path, $this->rasterize($svg, $size)->getImageBlob());
            $written[] = $path;
        }

        $ico = new \Imagick();
        foreach ([16, 32, 48] as $size) {
            $ico->addImage($this->rasterize(match ($size) {
                16 => BrandMark::svg('tiny', grid: 16),
                32 => $favicon,
                default => BrandMark::svg('medium', grid: 48),
            }, $size));
        }
        $ico->setFormat('ico');
        $filesystem->dumpFile($public.'/favicon.ico', $ico->getImagesBlob());
        $written[] = 'favicon.ico';

        $filesystem->dumpFile($public.'/images/podcast-cover.png', $this->cover()->getImageBlob());
        $written[] = 'images/podcast-cover.png';

        $io->listing(array_map(static fn (string $path): string => 'public/'.$path, $written));
        $io->success('Icons written.');

        return 0;
    }

    /**
     * Renders the SVG four times larger than needed, then averages each 4 by 4 block: smooth edges, and crisp ones where the bars sit on the pixel grid.
     */
    private function rasterize(string $svg, int $size): \Imagick
    {
        $image = new \Imagick();
        $image->setBackgroundColor(new \ImagickPixel('transparent'));
        // ImageMagick reads SVG at 96 dpi: this density gives an image of 4 times the size.
        $image->setResolution(96 * 4 * $size / BrandMark::VIEWBOX, 96 * 4 * $size / BrandMark::VIEWBOX);
        $image->readImageBlob($svg);
        $image->resizeImage($size, $size, \Imagick::FILTER_BOX, 1);
        $image->setImageFormat('png');
        $image->setImageDepth(8);
        $image->stripImage();

        return $image;
    }

    /**
     * The podcast cover: the mark full bleed (apps round the corners themselves) and the wordmark.
     */
    private function cover(): \Imagick
    {
        $size = 1400;
        $image = $this->rasterize(BrandMark::svg('full', rounded: false, scale: 0.62, offset: -0.07), $size);

        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel('#ffffff'));
        foreach (self::SERIF_FONTS as $font) {
            if (is_file($font)) {
                $draw->setFont($font);
                break;
            }
        }
        $draw->setFontSize(150);
        $draw->setTextKerning(2);
        $draw->setGravity(\Imagick::GRAVITY_SOUTH);
        $image->annotateImage($draw, 0, 190, 0, 'Crieur');
        // Podcast apps expect an opaque 8-bit RGB image.
        $image->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        $image->setImageType(\Imagick::IMGTYPE_TRUECOLOR);
        $image->setImageDepth(8);
        $image->setImageFormat('png');
        $image->setOption('png:color-type', '2');

        return $image;
    }
}

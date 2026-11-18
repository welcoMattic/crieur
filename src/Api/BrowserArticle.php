<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * An article the browser extension extracted with Readability, from the page the reader sees.
 */
final readonly class BrowserArticle
{
    public const int MAX_CONTENT = 3_000_000;

    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 2048)]
        #[Assert\Url(protocols: ['http', 'https'], requireTld: false)]
        public string $url,
        #[Assert\NotBlank]
        #[Assert\Length(max: 512)]
        public string $title,
        /** The HTML of the readable content, as Readability returns it. */
        #[Assert\NotBlank]
        #[Assert\Length(max: self::MAX_CONTENT)]
        public string $content,
        #[Assert\Length(max: 255)]
        public ?string $siteName = null,
        #[Assert\Length(max: 255)]
        public ?string $byline = null,
        #[Assert\Length(max: 35)]
        public ?string $lang = null,
        #[Assert\Length(max: 2000)]
        public ?string $excerpt = null,
        #[Assert\Length(max: 2048)]
        #[Assert\Url(protocols: ['http', 'https'], requireTld: false)]
        public ?string $image = null,
        #[Assert\Length(max: 64)]
        public ?string $publishedTime = null,
        #[Assert\Choice(choices: ['auto', 'fr', 'en'])]
        public string $language = 'auto',
        #[Assert\Choice(choices: ['female', 'male'])]
        public string $voice = 'female',
    ) {
    }
}

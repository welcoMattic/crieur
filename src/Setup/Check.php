<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * One part of the setup (a voice engine, the speech editor) and whether it can work now.
 */
final readonly class Check
{
    /**
     * @param ?string $issue a translation key ("setup.issue.*"), set when the part cannot work
     */
    public function __construct(
        public string $name,
        public string $label,
        public ?string $model,
        public bool $active,
        public bool $local,
        public bool $ready,
        public ?string $issue = null,
    ) {
    }
}

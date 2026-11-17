<?php

declare(strict_types=1);

namespace App\Library;

/**
 * The address is not a web page Crieur can fetch: another scheme, no host, or too long.
 */
final class InvalidArticleUrl extends \InvalidArgumentException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}

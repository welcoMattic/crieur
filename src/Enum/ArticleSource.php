<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How an article entered the library.
 */
enum ArticleSource: string
{
    case Web = 'web';
    case Bookmarklet = 'bookmarklet';
    case Mcp = 'mcp';
    case Cli = 'cli';
    /** Extracted by the browser extension, with the access of the reader. */
    case Extension = 'extension';
}

<?php

declare(strict_types=1);

namespace App\Extraction;

/**
 * The page could not be turned into an article. The reason is a translation key of the
 * "errors" domain, shown to the listener as is.
 */
final class ExtractionFailed extends \RuntimeException
{
    public const string TIMEOUT = 'extraction.timeout';
    public const string HTTP_ERROR = 'extraction.http_error';
    public const string BLOCKED = 'extraction.blocked';
    public const string NOT_HTML = 'extraction.not_html';
    public const string NO_TEXT = 'extraction.no_text';
    public const string NETWORK = 'extraction.network';
    public const string PRIVATE_NETWORK = 'extraction.private_network';
    public const string UNSUPPORTED_LANGUAGE = 'extraction.unsupported_language';
    public const string EXTRACTOR = 'extraction.extractor';
    public const string TOO_LONG_FOR_DEMO = 'extraction.too_long_for_demo';

    public function __construct(
        public readonly string $reason,
        string $detail = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct('' === $detail ? $reason : \sprintf('%s: %s', $reason, $detail), 0, $previous);
    }
}

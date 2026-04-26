<?php

declare(strict_types=1);

namespace App\Http;

final class DocumentFetchException extends \RuntimeException
{
    public static function forUnexpectedStatus(string $url, int $statusCode): self
    {
        return new self(sprintf('Unexpected HTTP status %d while fetching "%s".', $statusCode, $url));
    }

    public static function forTransportError(string $url, ?\Throwable $previous): self
    {
        return new self(sprintf('Transport error while fetching "%s".', $url), previous: $previous);
    }
}

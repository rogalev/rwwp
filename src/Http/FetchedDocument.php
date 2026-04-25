<?php

declare(strict_types=1);

namespace App\Http;

final readonly class FetchedDocument
{
    public function __construct(
        public string $url,
        public int $statusCode,
        public string $content,
        public ?string $contentType,
        public string $userAgent,
        public \DateTimeImmutable $fetchedAt,
    ) {
    }

    public function contentLength(): int
    {
        return strlen($this->content);
    }
}

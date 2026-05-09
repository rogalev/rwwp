<?php

declare(strict_types=1);

namespace App\MainApi;

final readonly class ImageDownloadTask
{
    public function __construct(
        public string $id,
        public string $sourceName,
        public string $externalUrl,
        public string $imageUrl,
        public ?string $altText,
        public int $timeoutSeconds,
        public int $maxBytes,
    ) {
    }
}

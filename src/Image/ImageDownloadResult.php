<?php

declare(strict_types=1);

namespace App\Image;

final readonly class ImageDownloadResult
{
    public function __construct(
        public string $filePath,
        public int $statusCode,
        public ?string $contentType,
        public int $sizeBytes,
    ) {
    }
}

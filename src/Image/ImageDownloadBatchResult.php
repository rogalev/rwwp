<?php

declare(strict_types=1);

namespace App\Image;

final readonly class ImageDownloadBatchResult
{
    public function __construct(
        public int $tasks,
        public int $downloaded,
        public int $failed,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->failed > 0;
    }
}

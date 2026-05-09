<?php

declare(strict_types=1);

namespace App\Image;

interface ImageDownloadBatchProcessorInterface
{
    public function process(int $limit): ImageDownloadBatchResult;
}

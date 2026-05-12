<?php

declare(strict_types=1);

namespace App\MainApi;

final readonly class SendRawArticleResult
{
    public function __construct(
        public string $jobId,
        public bool $accepted,
        public string $externalUrl,
        public string $status,
    ) {
    }
}

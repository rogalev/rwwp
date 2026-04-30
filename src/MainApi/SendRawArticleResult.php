<?php

declare(strict_types=1);

namespace App\MainApi;

final readonly class SendRawArticleResult
{
    public function __construct(
        public string $id,
        public bool $created,
        public string $externalUrl,
        public string $contentHash,
    ) {
    }
}

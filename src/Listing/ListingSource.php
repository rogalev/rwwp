<?php

declare(strict_types=1);

namespace App\Listing;

final readonly class ListingSource
{
    public function __construct(
        public ListingSourceType $type,
        public string $sourceKey,
        public string $scopeKey,
        public string $url,
        /**
         * @var array<string, mixed>
         */
        public array $config = [],
        public ?int $requestTimeoutSeconds = null,
    ) {
        if ($this->sourceKey === '') {
            throw new \InvalidArgumentException('Listing source key must not be empty.');
        }

        if ($this->scopeKey === '') {
            throw new \InvalidArgumentException('Listing scope key must not be empty.');
        }

        if ($this->url === '') {
            throw new \InvalidArgumentException('Listing URL must not be empty.');
        }
    }
}

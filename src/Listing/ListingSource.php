<?php

declare(strict_types=1);

namespace App\Listing;

final readonly class ListingSource
{
    public function __construct(
        public ListingSourceType $type,
        public string $sourceCode,
        public string $categoryCode,
        public string $url,
        /**
         * @var array<string, mixed>
         */
        public array $config = [],
        public ?int $requestTimeoutSeconds = null,
    ) {
        if ($this->sourceCode === '') {
            throw new \InvalidArgumentException('Listing source code must not be empty.');
        }

        if ($this->categoryCode === '') {
            throw new \InvalidArgumentException('Listing category code must not be empty.');
        }

        if ($this->url === '') {
            throw new \InvalidArgumentException('Listing URL must not be empty.');
        }
    }
}

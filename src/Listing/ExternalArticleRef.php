<?php

declare(strict_types=1);

namespace App\Listing;

final readonly class ExternalArticleRef
{
    public function __construct(
        public string $externalUrl,
        public string $sourceKey,
        public string $scopeKey,
        public ListingSourceType $listingSourceType,
    ) {
        if ($this->externalUrl === '') {
            throw new \InvalidArgumentException('External article URL must not be empty.');
        }

        if ($this->sourceKey === '') {
            throw new \InvalidArgumentException('External article source key must not be empty.');
        }

        if ($this->scopeKey === '') {
            throw new \InvalidArgumentException('External article scope key must not be empty.');
        }
    }
}

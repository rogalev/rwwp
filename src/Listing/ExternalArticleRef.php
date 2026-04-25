<?php

declare(strict_types=1);

namespace App\Listing;

final readonly class ExternalArticleRef
{
    public function __construct(
        public string $externalUrl,
        public string $sourceCode,
        public string $categoryCode,
        public ListingSourceType $listingSourceType,
    ) {
        if ($this->externalUrl === '') {
            throw new \InvalidArgumentException('External article URL must not be empty.');
        }

        if ($this->sourceCode === '') {
            throw new \InvalidArgumentException('External article source code must not be empty.');
        }

        if ($this->categoryCode === '') {
            throw new \InvalidArgumentException('External article category code must not be empty.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Listing;

final readonly class HtmlListingSelectorStats
{
    public function __construct(
        public string $selector,
        public int $matchedNodes,
        public int $uniqueUrls,
    ) {
    }
}

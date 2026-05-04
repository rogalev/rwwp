<?php

declare(strict_types=1);

namespace App\Pipeline;

final readonly class AssignmentListingEnqueueResult
{
    public function __construct(
        public int $found,
        public int $alreadySeen,
        public int $queued,
        public int $failed,
        public int $transportErrors = 0,
        public string $stage = 'listing',
        public string $lastError = '',
    ) {
    }
}

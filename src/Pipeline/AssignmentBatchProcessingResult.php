<?php

declare(strict_types=1);

namespace App\Pipeline;

final readonly class AssignmentBatchProcessingResult
{
    /**
     * @param array<int, int> $httpStatusCodes
     */
    public function __construct(
        public string $assignmentId,
        public string $source,
        public int $found,
        public int $alreadySeen,
        public int $queued,
        public int $sent,
        public int $failed,
        public array $httpStatusCodes = [],
        public int $transportErrors = 0,
        public string $stage = 'idle',
        public string $error = '',
        public bool $skipped = false,
    ) {
    }
}

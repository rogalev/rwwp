<?php

declare(strict_types=1);

namespace App\Pipeline;

final readonly class AssignmentBatchProcessingResult
{
    public function __construct(
        public string $assignmentId,
        public string $source,
        public int $found,
        public int $alreadySeen,
        public int $sent,
        public int $failed,
        public string $error = '',
    ) {
    }
}

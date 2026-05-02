<?php

declare(strict_types=1);

namespace App\Pipeline;

final readonly class AssignmentsBatchProcessingResult
{
    /**
     * @param list<AssignmentBatchProcessingResult> $assignmentResults
     * @param list<array{assignmentId: string, source: string, error: string}> $assignmentErrors
     * @param array<int, int> $httpStatusCodes
     */
    public function __construct(
        public int $assignments,
        public int $found,
        public int $alreadySeen,
        public int $sent,
        public int $failed,
        public array $assignmentResults,
        public array $assignmentErrors,
        public string $lastError,
        public array $httpStatusCodes = [],
        public int $transportErrors = 0,
        public string $stage = 'listing',
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->assignmentErrors !== [] || $this->lastError !== '';
    }
}

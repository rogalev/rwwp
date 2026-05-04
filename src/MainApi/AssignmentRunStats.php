<?php

declare(strict_types=1);

namespace App\MainApi;

final readonly class AssignmentRunStats
{
    /**
     * @param array<int, int> $httpStatusCodes
     */
    public function __construct(
        public string $assignmentId,
        public string $stage,
        public string $status,
        public int $found,
        public int $queued,
        public int $alreadySeen,
        public int $sent,
        public int $failed,
        public bool $skipped,
        public array $httpStatusCodes,
        public int $transportErrors,
        public int $durationMs,
        public string $lastError = '',
    ) {
        $this->assertNotBlank($this->assignmentId, 'assignmentId');
        $this->assertNotBlank($this->stage, 'stage');
        $this->assertNotBlank($this->status, 'status');
        $this->assertNonNegative($this->found, 'found');
        $this->assertNonNegative($this->queued, 'queued');
        $this->assertNonNegative($this->alreadySeen, 'alreadySeen');
        $this->assertNonNegative($this->sent, 'sent');
        $this->assertNonNegative($this->failed, 'failed');
        $this->assertNonNegative($this->transportErrors, 'transportErrors');
        $this->assertNonNegative($this->durationMs, 'durationMs');
    }

    private function assertNotBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException($field.' must not be blank.');
        }
    }

    private function assertNonNegative(int $value, string $field): void
    {
        if ($value < 0) {
            throw new \InvalidArgumentException($field.' must be non-negative.');
        }
    }
}

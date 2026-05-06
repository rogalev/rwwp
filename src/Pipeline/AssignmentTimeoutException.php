<?php

declare(strict_types=1);

namespace App\Pipeline;

final class AssignmentTimeoutException extends \RuntimeException
{
    public function __construct(
        public readonly string $assignmentId,
        public readonly string $source,
        public readonly int $timeoutSeconds,
        public readonly string $stage,
    ) {
        parent::__construct(sprintf(
            'Assignment "%s" timed out after %d seconds.',
            $assignmentId,
            $timeoutSeconds,
        ));
    }
}

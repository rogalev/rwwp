<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\MainApi\ParserAssignment;
use App\Schedule\AssignmentScheduleDecision;

final readonly class DirectAssignmentProcessorGuard implements AssignmentProcessorGuardInterface
{
    public function __construct(
        private ScheduledAssignmentProcessor $processor,
    ) {
    }

    public function process(
        ParserAssignment $assignment,
        AssignmentScheduleDecision $scheduleDecision,
        int $limit,
    ): ScheduledAssignmentProcessingResult {
        return $this->processor->process($assignment, $scheduleDecision, $limit);
    }
}

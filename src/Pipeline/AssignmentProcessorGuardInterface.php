<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\MainApi\ParserAssignment;
use App\Schedule\AssignmentScheduleDecision;

interface AssignmentProcessorGuardInterface
{
    public function process(
        ParserAssignment $assignment,
        AssignmentScheduleDecision $scheduleDecision,
        int $limit,
    ): ScheduledAssignmentProcessingResult;
}

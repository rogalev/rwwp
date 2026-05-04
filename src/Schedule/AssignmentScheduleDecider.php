<?php

declare(strict_types=1);

namespace App\Schedule;

use App\MainApi\ParserAssignment;
use App\State\AssignmentScheduleStoreInterface;

final readonly class AssignmentScheduleDecider
{
    public function __construct(
        private AssignmentScheduleStoreInterface $scheduleStore,
    ) {
    }

    public function decide(ParserAssignment $assignment, \DateTimeImmutable $now): AssignmentScheduleDecision
    {
        return new AssignmentScheduleDecision(
            listingDue: $this->isDue(
                $this->scheduleStore->lastListingCheckedAt($assignment->assignmentId),
                $assignment->listingCheckIntervalSeconds,
                $now,
            ),
            articleFetchDue: $this->isDue(
                $this->scheduleStore->lastArticleFetchedAt($assignment->assignmentId),
                $assignment->articleFetchIntervalSeconds,
                $now,
            ),
        );
    }

    private function isDue(?\DateTimeImmutable $lastRunAt, int $intervalSeconds, \DateTimeImmutable $now): bool
    {
        if ($lastRunAt === null) {
            return true;
        }

        return $now->getTimestamp() - $lastRunAt->getTimestamp() >= $intervalSeconds;
    }
}

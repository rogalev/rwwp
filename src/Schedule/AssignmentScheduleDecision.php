<?php

declare(strict_types=1);

namespace App\Schedule;

final readonly class AssignmentScheduleDecision
{
    public function __construct(
        public bool $listingDue,
        public bool $articleFetchDue,
    ) {
    }

    public function hasDueWork(): bool
    {
        return $this->listingDue || $this->articleFetchDue;
    }
}

<?php

declare(strict_types=1);

namespace App\State;

interface AssignmentScheduleStoreInterface
{
    public function lastListingCheckedAt(string $assignmentId): ?\DateTimeImmutable;

    public function markListingChecked(string $assignmentId, \DateTimeImmutable $checkedAt): void;

    public function lastArticleFetchedAt(string $assignmentId): ?\DateTimeImmutable;

    public function markArticleFetched(string $assignmentId, \DateTimeImmutable $fetchedAt): void;
}

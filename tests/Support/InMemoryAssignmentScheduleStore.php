<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\State\AssignmentScheduleStoreInterface;

final class InMemoryAssignmentScheduleStore implements AssignmentScheduleStoreInterface
{
    /**
     * @var array<string, \DateTimeImmutable>
     */
    private array $listingCheckedAt = [];

    /**
     * @var array<string, \DateTimeImmutable>
     */
    private array $articleFetchedAt = [];

    public function lastListingCheckedAt(string $assignmentId): ?\DateTimeImmutable
    {
        return $this->listingCheckedAt[$assignmentId] ?? null;
    }

    public function markListingChecked(string $assignmentId, \DateTimeImmutable $checkedAt): void
    {
        $this->listingCheckedAt[$assignmentId] = $checkedAt;
    }

    public function lastArticleFetchedAt(string $assignmentId): ?\DateTimeImmutable
    {
        return $this->articleFetchedAt[$assignmentId] ?? null;
    }

    public function markArticleFetched(string $assignmentId, \DateTimeImmutable $fetchedAt): void
    {
        $this->articleFetchedAt[$assignmentId] = $fetchedAt;
    }
}

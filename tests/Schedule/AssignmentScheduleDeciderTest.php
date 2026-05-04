<?php

declare(strict_types=1);

namespace App\Tests\Schedule;

use App\MainApi\ParserAssignment;
use App\Schedule\AssignmentScheduleDecider;
use App\State\AssignmentScheduleStoreInterface;
use PHPUnit\Framework\TestCase;

final class AssignmentScheduleDeciderTest extends TestCase
{
    public function testMarksBothStagesDueWhenAssignmentWasNeverProcessed(): void
    {
        $decider = new AssignmentScheduleDecider(new InMemoryAssignmentScheduleStore());

        $decision = $decider->decide(
            $this->assignment(),
            new \DateTimeImmutable('2026-05-04T10:00:00+00:00'),
        );

        self::assertTrue($decision->listingDue);
        self::assertTrue($decision->articleFetchDue);
        self::assertTrue($decision->hasDueWork());
    }

    public function testMarksStagesNotDueBeforeIntervalsPass(): void
    {
        $store = new InMemoryAssignmentScheduleStore();
        $store->markListingChecked('assignment-1', new \DateTimeImmutable('2026-05-04T10:00:00+00:00'));
        $store->markArticleFetched('assignment-1', new \DateTimeImmutable('2026-05-04T10:00:00+00:00'));
        $decider = new AssignmentScheduleDecider($store);

        $decision = $decider->decide(
            $this->assignment(listingInterval: 300, articleInterval: 10),
            new \DateTimeImmutable('2026-05-04T10:00:09+00:00'),
        );

        self::assertFalse($decision->listingDue);
        self::assertFalse($decision->articleFetchDue);
        self::assertFalse($decision->hasDueWork());
    }

    public function testMarksStageDueWhenIntervalBoundaryIsReached(): void
    {
        $store = new InMemoryAssignmentScheduleStore();
        $store->markListingChecked('assignment-1', new \DateTimeImmutable('2026-05-04T10:00:00+00:00'));
        $store->markArticleFetched('assignment-1', new \DateTimeImmutable('2026-05-04T10:00:00+00:00'));
        $decider = new AssignmentScheduleDecider($store);

        $decision = $decider->decide(
            $this->assignment(listingInterval: 300, articleInterval: 10),
            new \DateTimeImmutable('2026-05-04T10:00:10+00:00'),
        );

        self::assertFalse($decision->listingDue);
        self::assertTrue($decision->articleFetchDue);
        self::assertTrue($decision->hasDueWork());
    }

    public function testListingAndArticleIntervalsAreIndependent(): void
    {
        $store = new InMemoryAssignmentScheduleStore();
        $store->markListingChecked('assignment-1', new \DateTimeImmutable('2026-05-04T10:00:00+00:00'));
        $store->markArticleFetched('assignment-1', new \DateTimeImmutable('2026-05-04T10:00:00+00:00'));
        $decider = new AssignmentScheduleDecider($store);

        $decision = $decider->decide(
            $this->assignment(listingInterval: 300, articleInterval: 10),
            new \DateTimeImmutable('2026-05-04T10:05:00+00:00'),
        );

        self::assertTrue($decision->listingDue);
        self::assertTrue($decision->articleFetchDue);
    }

    private function assignment(int $listingInterval = 300, int $articleInterval = 10): ParserAssignment
    {
        return new ParserAssignment(
            assignmentId: 'assignment-1',
            sourceId: 'source-1',
            sourceDisplayName: 'BBC',
            listingMode: 'rss',
            listingUrl: 'https://feeds.bbci.co.uk/news/world/rss.xml',
            articleMode: 'html',
            listingCheckIntervalSeconds: $listingInterval,
            articleFetchIntervalSeconds: $articleInterval,
            requestTimeoutSeconds: 15,
            config: [],
        );
    }
}

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

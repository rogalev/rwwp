<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\State\SqliteAssignmentScheduleStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteAssignmentScheduleStoreTest extends TestCase
{
    public function testReturnsNullWhenAssignmentWasNotScheduledYet(): void
    {
        $store = new SqliteAssignmentScheduleStore('sqlite:'.$this->temporaryStatePath());

        self::assertNull($store->lastListingCheckedAt('assignment-1'));
        self::assertNull($store->lastArticleFetchedAt('assignment-1'));
    }

    public function testPersistsListingAndArticleRunTimestamps(): void
    {
        $path = $this->temporaryStatePath();
        $store = new SqliteAssignmentScheduleStore('sqlite:'.$path);
        $listingCheckedAt = new \DateTimeImmutable('2026-05-04T10:00:00+00:00');
        $articleFetchedAt = new \DateTimeImmutable('2026-05-04T10:00:10+00:00');

        $store->markListingChecked('assignment-1', $listingCheckedAt);
        $store->markArticleFetched('assignment-1', $articleFetchedAt);

        self::assertSame(
            $listingCheckedAt->format(\DateTimeInterface::ATOM),
            $store->lastListingCheckedAt('assignment-1')?->format(\DateTimeInterface::ATOM),
        );
        self::assertSame(
            $articleFetchedAt->format(\DateTimeInterface::ATOM),
            $store->lastArticleFetchedAt('assignment-1')?->format(\DateTimeInterface::ATOM),
        );

        $row = $this->findSchedule($path, 'assignment-1');
        self::assertSame('2026-05-04T10:00:00+00:00', $row['last_listing_checked_at']);
        self::assertSame('2026-05-04T10:00:10+00:00', $row['last_article_fetched_at']);
    }

    public function testScheduleSurvivesNewStoreInstanceForSameSqliteFile(): void
    {
        $path = $this->temporaryStatePath();
        $firstStore = new SqliteAssignmentScheduleStore('sqlite:'.$path);
        $checkedAt = new \DateTimeImmutable('2026-05-04T10:00:00+00:00');

        $firstStore->markListingChecked('assignment-1', $checkedAt);

        $secondStore = new SqliteAssignmentScheduleStore('sqlite:'.$path);

        self::assertSame(
            $checkedAt->format(\DateTimeInterface::ATOM),
            $secondStore->lastListingCheckedAt('assignment-1')?->format(\DateTimeInterface::ATOM),
        );
    }

    private function temporaryStatePath(): string
    {
        return sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/state/parser.sqlite';
    }

    /**
     * @return array{last_listing_checked_at: ?string, last_article_fetched_at: ?string}
     */
    private function findSchedule(string $path, string $assignmentId): array
    {
        $connection = new PDO('sqlite:'.$path);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $statement = $connection->prepare(
            <<<'SQL'
            SELECT last_listing_checked_at, last_article_fetched_at
            FROM assignment_schedule
            WHERE assignment_id = :assignmentId
            SQL
        );
        $statement->execute(['assignmentId' => $assignmentId]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}

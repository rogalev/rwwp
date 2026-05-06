<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\State\SqlitePendingArticleQueue;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqlitePendingArticleQueueTest extends TestCase
{
    public function testEnqueuePersistsPendingArticle(): void
    {
        $path = $this->temporaryStatePath();
        $queue = new SqlitePendingArticleQueue('sqlite:'.$path);

        self::assertTrue($queue->enqueue('assignment-1', 'https://example.com/news/1', 'source-1'));

        $items = $queue->takePending('assignment-1', 10);
        self::assertCount(1, $items);
        self::assertSame('assignment-1', $items[0]->assignmentId);
        self::assertSame('https://example.com/news/1', $items[0]->externalUrl);
        self::assertSame('source-1', $items[0]->sourceCode);

        $row = $this->findArticle($path, 'assignment-1', 'https://example.com/news/1');
        self::assertSame('pending', $row['status']);
        self::assertNull($row['last_attempt_at']);
        self::assertNull($row['error']);
    }

    public function testEnqueueIsIdempotentPerAssignmentAndUrl(): void
    {
        $queue = new SqlitePendingArticleQueue('sqlite:'.$this->temporaryStatePath());

        self::assertTrue($queue->enqueue('assignment-1', 'https://example.com/news/1', 'source-1'));
        self::assertFalse($queue->enqueue('assignment-1', 'https://example.com/news/1', 'source-1'));
        self::assertTrue($queue->enqueue('assignment-2', 'https://example.com/news/1', 'source-1'));

        self::assertCount(1, $queue->takePending('assignment-1', 10));
        self::assertCount(1, $queue->takePending('assignment-2', 10));
    }

    public function testTakePendingRespectsLimitAndAssignment(): void
    {
        $queue = new SqlitePendingArticleQueue('sqlite:'.$this->temporaryStatePath());
        $queue->enqueue('assignment-1', 'https://example.com/news/1', 'source-1');
        $queue->enqueue('assignment-1', 'https://example.com/news/2', 'source-1');
        $queue->enqueue('assignment-2', 'https://example.com/news/3', 'source-1');

        $items = $queue->takePending('assignment-1', 1);

        self::assertCount(1, $items);
        self::assertSame('https://example.com/news/1', $items[0]->externalUrl);
    }

    public function testMarkSentRemovesArticleFromPendingSelection(): void
    {
        $path = $this->temporaryStatePath();
        $queue = new SqlitePendingArticleQueue('sqlite:'.$path);
        $queue->enqueue('assignment-1', 'https://example.com/news/1', 'source-1');

        $queue->markSent('assignment-1', 'https://example.com/news/1');

        self::assertSame([], $queue->takePending('assignment-1', 10));

        $row = $this->findArticle($path, 'assignment-1', 'https://example.com/news/1');
        self::assertSame('sent', $row['status']);
        self::assertIsString($row['last_attempt_at']);
        self::assertNull($row['error']);
    }

    public function testMarkFailedRemovesArticleFromPendingSelectionAndStoresError(): void
    {
        $path = $this->temporaryStatePath();
        $queue = new SqlitePendingArticleQueue('sqlite:'.$path);
        $queue->enqueue('assignment-1', 'https://example.com/news/1', 'source-1');

        $queue->markFailed('assignment-1', 'https://example.com/news/1', 'HTTP 403');

        self::assertSame([], $queue->takePending('assignment-1', 10));

        $row = $this->findArticle($path, 'assignment-1', 'https://example.com/news/1');
        self::assertSame('failed', $row['status']);
        self::assertIsString($row['last_attempt_at']);
        self::assertSame(1, $row['attempt_count']);
        self::assertSame('HTTP 403', $row['error']);
    }

    public function testRetriesFailedArticleAfterRetryDelay(): void
    {
        $path = $this->temporaryStatePath();
        $queue = new SqlitePendingArticleQueue('sqlite:'.$path);
        $queue->enqueue('assignment-1', 'https://example.com/news/1', 'source-1');
        $queue->markFailed('assignment-1', 'https://example.com/news/1', 'Temporary main API error.');
        $this->setLastAttemptAt($path, 'assignment-1', 'https://example.com/news/1', '2000-01-01T00:00:00+00:00');

        $items = $queue->takePending('assignment-1', 10);

        self::assertCount(1, $items);
        self::assertSame('https://example.com/news/1', $items[0]->externalUrl);
    }

    public function testStopsRetryingFailedArticleAfterMaxAttempts(): void
    {
        $path = $this->temporaryStatePath();
        $queue = new SqlitePendingArticleQueue('sqlite:'.$path);
        $queue->enqueue('assignment-1', 'https://example.com/news/1', 'source-1');
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $queue->markFailed('assignment-1', 'https://example.com/news/1', 'Temporary main API error.');
            $this->setLastAttemptAt($path, 'assignment-1', 'https://example.com/news/1', '2000-01-01T00:00:00+00:00');
        }

        self::assertSame([], $queue->takePending('assignment-1', 10));

        $row = $this->findArticle($path, 'assignment-1', 'https://example.com/news/1');
        self::assertSame(5, $row['attempt_count']);
    }

    public function testRejectsNonPositiveLimit(): void
    {
        $queue = new SqlitePendingArticleQueue('sqlite:'.$this->temporaryStatePath());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('limit must be greater than zero.');

        $queue->takePending('assignment-1', 0);
    }

    private function temporaryStatePath(): string
    {
        return sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/state/parser.sqlite';
    }

    /**
     * @return array{status: string, last_attempt_at: ?string, attempt_count: int, error: ?string}
     */
    private function findArticle(string $path, string $assignmentId, string $externalUrl): array
    {
        $connection = new PDO('sqlite:'.$path);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $statement = $connection->prepare(
            <<<'SQL'
            SELECT status, last_attempt_at, attempt_count, error
            FROM pending_articles
            WHERE assignment_id = :assignmentId
              AND external_url = :externalUrl
            SQL
        );
        $statement->execute([
            'assignmentId' => $assignmentId,
            'externalUrl' => $externalUrl,
        ]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $row['attempt_count'] = (int) $row['attempt_count'];

        return $row;
    }

    private function setLastAttemptAt(string $path, string $assignmentId, string $externalUrl, string $lastAttemptAt): void
    {
        $connection = new PDO('sqlite:'.$path);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $statement = $connection->prepare(
            <<<'SQL'
            UPDATE pending_articles
            SET last_attempt_at = :lastAttemptAt
            WHERE assignment_id = :assignmentId
              AND external_url = :externalUrl
            SQL
        );
        $statement->execute([
            'lastAttemptAt' => $lastAttemptAt,
            'assignmentId' => $assignmentId,
            'externalUrl' => $externalUrl,
        ]);
    }
}

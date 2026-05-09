<?php

declare(strict_types=1);

namespace App\Tests\Status;

use App\Status\RuntimeMetricsCollector;
use PDO;
use PHPUnit\Framework\TestCase;

final class RuntimeMetricsCollectorTest extends TestCase
{
    public function testCollectsSqliteAndFileMetrics(): void
    {
        $directory = sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8));
        $statePath = $directory.'/state/parser.sqlite';
        $diagnosticLogPath = $directory.'/log/parser-diagnostic.ndjson';
        mkdir(dirname($statePath), 0775, true);
        mkdir(dirname($diagnosticLogPath), 0775, true);

        $connection = new PDO('sqlite:'.$statePath);
        $connection->exec(
            <<<'SQL'
            CREATE TABLE pending_articles (
                assignment_id TEXT NOT NULL,
                external_url TEXT NOT NULL,
                source_code TEXT NOT NULL,
                status TEXT NOT NULL,
                first_seen_at TEXT NOT NULL
            )
            SQL
        );
        $connection->exec(
            <<<'SQL'
            CREATE TABLE seen_articles (
                external_url TEXT PRIMARY KEY
            )
            SQL
        );
        $oldestPending = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-90 seconds')
            ->format(\DateTimeInterface::ATOM);
        $connection->prepare('INSERT INTO pending_articles VALUES (:assignment, :url, :source, :status, :firstSeenAt)')
            ->execute([
                'assignment' => 'assignment-1',
                'url' => 'https://example.com/1',
                'source' => 'bbc',
                'status' => 'pending',
                'firstSeenAt' => $oldestPending,
            ]);
        $connection->prepare('INSERT INTO pending_articles VALUES (:assignment, :url, :source, :status, :firstSeenAt)')
            ->execute([
                'assignment' => 'assignment-1',
                'url' => 'https://example.com/2',
                'source' => 'bbc',
                'status' => 'failed',
                'firstSeenAt' => $oldestPending,
            ]);
        $connection->exec("INSERT INTO seen_articles VALUES ('https://example.com/1')");
        file_put_contents($diagnosticLogPath, "line\n");

        $metrics = (new RuntimeMetricsCollector(
            stateDsn: 'sqlite:'.$statePath,
            diagnosticLogPath: $diagnosticLogPath,
            hostLabel: 'nl3 parser',
        ))->collect();

        self::assertSame('nl3 parser', $metrics['hostLabel']);
        self::assertSame(1, $metrics['pendingQueueSize']);
        self::assertSame(1, $metrics['failedQueueSize']);
        self::assertSame(1, $metrics['seenArticlesCount']);
        self::assertIsInt($metrics['sqliteStateSizeBytes']);
        self::assertGreaterThan(0, $metrics['sqliteStateSizeBytes']);
        self::assertSame(5, $metrics['diagnosticLogSizeBytes']);
        self::assertIsInt($metrics['oldestPendingAgeSeconds']);
        self::assertGreaterThanOrEqual(0, $metrics['oldestPendingAgeSeconds']);
    }

    public function testReturnsNullQueueMetricsWhenSqliteDatabaseDoesNotExist(): void
    {
        $metrics = (new RuntimeMetricsCollector(
            stateDsn: 'sqlite:'.sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/missing.sqlite',
            diagnosticLogPath: sys_get_temp_dir().'/missing-parser-diagnostic.ndjson',
        ))->collect();

        self::assertNull($metrics['pendingQueueSize']);
        self::assertNull($metrics['failedQueueSize']);
        self::assertNull($metrics['seenArticlesCount']);
        self::assertNull($metrics['oldestPendingAgeSeconds']);
    }
}

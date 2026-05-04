<?php

declare(strict_types=1);

namespace App\State;

use PDO;

final class SqlitePendingArticleQueue implements PendingArticleQueueInterface
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly string $dsn,
    ) {
    }

    public function enqueue(string $assignmentId, string $externalUrl, string $sourceCode): bool
    {
        $now = $this->now();
        $statement = $this->connection()->prepare(
            <<<'SQL'
            INSERT INTO pending_articles (
                assignment_id,
                external_url,
                source_code,
                status,
                first_seen_at,
                updated_at
            ) VALUES (
                :assignmentId,
                :externalUrl,
                :sourceCode,
                :status,
                :firstSeenAt,
                :updatedAt
            )
            ON CONFLICT(assignment_id, external_url) DO NOTHING
            SQL
        );

        $statement->execute([
            'assignmentId' => $assignmentId,
            'externalUrl' => $externalUrl,
            'sourceCode' => $sourceCode,
            'status' => 'pending',
            'firstSeenAt' => $now,
            'updatedAt' => $now,
        ]);

        return $statement->rowCount() === 1;
    }

    public function takePending(string $assignmentId, int $limit): array
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('limit must be greater than zero.');
        }

        $statement = $this->connection()->prepare(
            <<<'SQL'
            SELECT assignment_id, external_url, source_code
            FROM pending_articles
            WHERE assignment_id = :assignmentId
              AND status = :status
            ORDER BY first_seen_at ASC, external_url ASC
            LIMIT :limit
            SQL
        );
        $statement->bindValue('assignmentId', $assignmentId);
        $statement->bindValue('status', 'pending');
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $items = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = new PendingArticle(
                assignmentId: (string) $row['assignment_id'],
                externalUrl: (string) $row['external_url'],
                sourceCode: (string) $row['source_code'],
            );
        }

        return $items;
    }

    public function markSent(string $assignmentId, string $externalUrl): void
    {
        $this->markProcessed($assignmentId, $externalUrl, 'sent', null);
    }

    public function markFailed(string $assignmentId, string $externalUrl, string $error): void
    {
        $this->markProcessed($assignmentId, $externalUrl, 'failed', $error);
    }

    private function markProcessed(string $assignmentId, string $externalUrl, string $status, ?string $error): void
    {
        $statement = $this->connection()->prepare(
            <<<'SQL'
            UPDATE pending_articles
            SET status = :status,
                last_attempt_at = :lastAttemptAt,
                updated_at = :updatedAt,
                error = :error
            WHERE assignment_id = :assignmentId
              AND external_url = :externalUrl
            SQL
        );

        $now = $this->now();
        $statement->execute([
            'assignmentId' => $assignmentId,
            'externalUrl' => $externalUrl,
            'status' => $status,
            'lastAttemptAt' => $now,
            'updatedAt' => $now,
            'error' => $error,
        ]);
    }

    private function connection(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $this->ensureSqliteDirectoryExists($this->dsn);

        $this->connection = new PDO($this->dsn);
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->connection->exec('PRAGMA journal_mode = WAL');
        $this->connection->exec('PRAGMA foreign_keys = ON');
        $this->migrate($this->connection);

        return $this->connection;
    }

    private function migrate(PDO $connection): void
    {
        $connection->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS pending_articles (
                assignment_id TEXT NOT NULL,
                external_url TEXT NOT NULL,
                source_code TEXT NOT NULL,
                status TEXT NOT NULL,
                first_seen_at TEXT NOT NULL,
                last_attempt_at TEXT DEFAULT NULL,
                updated_at TEXT NOT NULL,
                error TEXT DEFAULT NULL,
                PRIMARY KEY (assignment_id, external_url)
            )
            SQL
        );

        $connection->exec(
            <<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_pending_articles_assignment_status_seen
            ON pending_articles (assignment_id, status, first_seen_at)
            SQL
        );
    }

    private function ensureSqliteDirectoryExists(string $dsn): void
    {
        if (!str_starts_with($dsn, 'sqlite:')) {
            return;
        }

        $path = substr($dsn, strlen('sqlite:'));

        if ($path === ':memory:') {
            return;
        }

        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create SQLite state directory "%s".', $directory));
        }
    }

    private function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
    }
}

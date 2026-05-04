<?php

declare(strict_types=1);

namespace App\State;

use PDO;

final class SqliteAssignmentScheduleStore implements AssignmentScheduleStoreInterface
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly string $dsn,
    ) {
    }

    public function lastListingCheckedAt(string $assignmentId): ?\DateTimeImmutable
    {
        return $this->readDateTime($assignmentId, 'last_listing_checked_at');
    }

    public function markListingChecked(string $assignmentId, \DateTimeImmutable $checkedAt): void
    {
        $this->upsertDateTime($assignmentId, 'last_listing_checked_at', $checkedAt);
    }

    public function lastArticleFetchedAt(string $assignmentId): ?\DateTimeImmutable
    {
        return $this->readDateTime($assignmentId, 'last_article_fetched_at');
    }

    public function markArticleFetched(string $assignmentId, \DateTimeImmutable $fetchedAt): void
    {
        $this->upsertDateTime($assignmentId, 'last_article_fetched_at', $fetchedAt);
    }

    private function readDateTime(string $assignmentId, string $column): ?\DateTimeImmutable
    {
        $this->assertAllowedColumn($column);

        $statement = $this->connection()->prepare(
            sprintf(
                'SELECT %s FROM assignment_schedule WHERE assignment_id = :assignmentId LIMIT 1',
                $column,
            ),
        );
        $statement->execute(['assignmentId' => $assignmentId]);

        $value = $statement->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }

        $dateTime = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, (string) $value);
        if (!$dateTime instanceof \DateTimeImmutable) {
            throw new \RuntimeException(sprintf('Invalid assignment schedule datetime "%s".', $value));
        }

        return $dateTime;
    }

    private function upsertDateTime(string $assignmentId, string $column, \DateTimeImmutable $dateTime): void
    {
        $this->assertAllowedColumn($column);

        $now = $this->now();
        $statement = $this->connection()->prepare(
            sprintf(
                <<<'SQL'
                INSERT INTO assignment_schedule (
                    assignment_id,
                    %1$s,
                    created_at,
                    updated_at
                ) VALUES (
                    :assignmentId,
                    :value,
                    :createdAt,
                    :updatedAt
                )
                ON CONFLICT(assignment_id) DO UPDATE SET
                    %1$s = excluded.%1$s,
                    updated_at = excluded.updated_at
                SQL,
                $column,
            ),
        );

        $statement->execute([
            'assignmentId' => $assignmentId,
            'value' => $dateTime->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
            'createdAt' => $now,
            'updatedAt' => $now,
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
            CREATE TABLE IF NOT EXISTS assignment_schedule (
                assignment_id TEXT PRIMARY KEY,
                last_listing_checked_at TEXT DEFAULT NULL,
                last_article_fetched_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
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

    private function assertAllowedColumn(string $column): void
    {
        if (!\in_array($column, ['last_listing_checked_at', 'last_article_fetched_at'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported assignment schedule column "%s".', $column));
        }
    }
}

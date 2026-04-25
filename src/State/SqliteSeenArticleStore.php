<?php

declare(strict_types=1);

namespace App\State;

use PDO;

final class SqliteSeenArticleStore implements SeenArticleStoreInterface
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly string $dsn,
    ) {
    }

    public function has(string $externalUrl): bool
    {
        $statement = $this->connection()->prepare('SELECT 1 FROM seen_articles WHERE external_url = :externalUrl LIMIT 1');
        $statement->execute(['externalUrl' => $externalUrl]);

        return $statement->fetchColumn() !== false;
    }

    public function markSeen(string $externalUrl, string $sourceCode, string $categoryCode): void
    {
        $now = $this->now();

        $statement = $this->connection()->prepare(
            <<<'SQL'
            INSERT INTO seen_articles (
                external_url,
                source_code,
                category_code,
                first_seen_at,
                last_seen_at,
                status
            ) VALUES (
                :externalUrl,
                :sourceCode,
                :categoryCode,
                :firstSeenAt,
                :lastSeenAt,
                :status
            )
            ON CONFLICT(external_url) DO UPDATE SET
                source_code = excluded.source_code,
                category_code = excluded.category_code,
                last_seen_at = excluded.last_seen_at
            SQL
        );

        $statement->execute([
            'externalUrl' => $externalUrl,
            'sourceCode' => $sourceCode,
            'categoryCode' => $categoryCode,
            'firstSeenAt' => $now,
            'lastSeenAt' => $now,
            'status' => 'SEEN',
        ]);
    }

    public function markParsed(string $externalUrl): void
    {
        $statement = $this->connection()->prepare(
            <<<'SQL'
            UPDATE seen_articles
            SET parsed_at = :parsedAt,
                status = :status,
                error = NULL
            WHERE external_url = :externalUrl
            SQL
        );

        $statement->execute([
            'externalUrl' => $externalUrl,
            'parsedAt' => $this->now(),
            'status' => 'PARSED',
        ]);
    }

    public function markFailed(string $externalUrl, string $error): void
    {
        $statement = $this->connection()->prepare(
            <<<'SQL'
            UPDATE seen_articles
            SET status = :status,
                error = :error
            WHERE external_url = :externalUrl
            SQL
        );

        $statement->execute([
            'externalUrl' => $externalUrl,
            'status' => 'FAILED',
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
            CREATE TABLE IF NOT EXISTS seen_articles (
                external_url TEXT PRIMARY KEY,
                source_code TEXT NOT NULL,
                category_code TEXT NOT NULL,
                first_seen_at TEXT NOT NULL,
                last_seen_at TEXT NOT NULL,
                parsed_at TEXT DEFAULT NULL,
                status TEXT NOT NULL,
                error TEXT DEFAULT NULL
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
}

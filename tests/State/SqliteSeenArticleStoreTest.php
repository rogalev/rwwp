<?php

declare(strict_types=1);

namespace App\Tests\State;

use App\State\SqliteSeenArticleStore;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteSeenArticleStoreTest extends TestCase
{
    public function testMarkSeenPersistsArticleState(): void
    {
        $path = $this->temporaryStatePath();
        $store = new SqliteSeenArticleStore('sqlite:'.$path);
        $externalUrl = 'https://example.com/news/42';

        self::assertFalse($store->has($externalUrl));

        $store->markSeen($externalUrl, 'bbc', 'world');

        self::assertTrue($store->has($externalUrl));

        $row = $this->findArticleState($path, $externalUrl);
        self::assertSame('bbc', $row['source_code']);
        self::assertSame('world', $row['category_code']);
        self::assertSame('SEEN', $row['status']);
        self::assertNull($row['parsed_at']);
        self::assertNull($row['error']);
    }

    public function testMarkParsedUpdatesExistingArticleState(): void
    {
        $path = $this->temporaryStatePath();
        $store = new SqliteSeenArticleStore('sqlite:'.$path);
        $externalUrl = 'https://example.com/news/42';

        $store->markSeen($externalUrl, 'bbc', 'world');
        $store->markFailed($externalUrl, 'Temporary parser failure.');
        $store->markParsed($externalUrl);

        $row = $this->findArticleState($path, $externalUrl);
        self::assertSame('PARSED', $row['status']);
        self::assertIsString($row['parsed_at']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $row['parsed_at']));
        self::assertNull($row['error']);
    }

    public function testMarkFailedUpdatesExistingArticleState(): void
    {
        $path = $this->temporaryStatePath();
        $store = new SqliteSeenArticleStore('sqlite:'.$path);
        $externalUrl = 'https://example.com/news/42';

        $store->markSeen($externalUrl, 'bbc', 'world');
        $store->markFailed($externalUrl, 'Parser failed.');

        $row = $this->findArticleState($path, $externalUrl);
        self::assertSame('FAILED', $row['status']);
        self::assertSame('Parser failed.', $row['error']);
    }

    public function testStateSurvivesNewStoreInstanceForSameSqliteFile(): void
    {
        $path = $this->temporaryStatePath();
        $externalUrl = 'https://example.com/news/42';

        $firstStore = new SqliteSeenArticleStore('sqlite:'.$path);
        $firstStore->markSeen($externalUrl, 'bbc', 'world');

        $secondStore = new SqliteSeenArticleStore('sqlite:'.$path);

        self::assertTrue($secondStore->has($externalUrl));
    }

    private function temporaryStatePath(): string
    {
        return sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/state/parser.sqlite';
    }

    /**
     * @return array{source_code: string, category_code: string, parsed_at: ?string, status: string, error: ?string}
     */
    private function findArticleState(string $path, string $externalUrl): array
    {
        $connection = new PDO('sqlite:'.$path);
        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $statement = $connection->prepare(
            <<<'SQL'
            SELECT source_code, category_code, parsed_at, status, error
            FROM seen_articles
            WHERE external_url = :externalUrl
            SQL
        );
        $statement->execute(['externalUrl' => $externalUrl]);

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}

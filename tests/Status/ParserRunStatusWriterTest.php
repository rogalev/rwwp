<?php

declare(strict_types=1);

namespace App\Tests\Status;

use App\Status\ParserRunStatusWriter;
use PHPUnit\Framework\TestCase;

final class ParserRunStatusWriterTest extends TestCase
{
    public function testWriteCreatesDirectoryAndWritesStatusJson(): void
    {
        $path = $this->temporaryStatusPath();
        $writer = new ParserRunStatusWriter($path);

        $writer->write([
            'sourceCode' => 'bbc',
            'categoryCode' => 'world',
            'listingType' => 'rss_feed',
            'found' => 3,
            'alreadySeen' => 1,
            'processed' => 2,
            'parsed' => 1,
            'failed' => 1,
            'unsupported' => 0,
            'lastError' => 'Parser failed.',
        ]);

        self::assertFileExists($path);

        $payload = $this->readStatus($path);

        self::assertIsString($payload['checkedAt']);
        self::assertSame('bbc', $payload['sourceCode']);
        self::assertSame('world', $payload['categoryCode']);
        self::assertSame('rss_feed', $payload['listingType']);
        self::assertSame(3, $payload['found']);
        self::assertSame(1, $payload['alreadySeen']);
        self::assertSame(2, $payload['processed']);
        self::assertSame(1, $payload['parsed']);
        self::assertSame(1, $payload['failed']);
        self::assertSame(0, $payload['unsupported']);
        self::assertSame('Parser failed.', $payload['lastError']);
    }

    public function testWriteOverwritesPreviousStatus(): void
    {
        $path = $this->temporaryStatusPath();
        $writer = new ParserRunStatusWriter($path);

        $writer->write(['sourceCode' => 'first']);
        $writer->write(['sourceCode' => 'second']);

        $payload = $this->readStatus($path);

        self::assertSame('second', $payload['sourceCode']);
    }

    private function temporaryStatusPath(): string
    {
        return sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/status/parser-run.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function readStatus(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}

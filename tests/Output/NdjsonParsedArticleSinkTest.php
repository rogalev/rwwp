<?php

declare(strict_types=1);

namespace App\Tests\Output;

use App\Article\ParsedArticle;
use App\Output\NdjsonParsedArticleSink;
use PHPUnit\Framework\TestCase;

final class NdjsonParsedArticleSinkTest extends TestCase
{
    public function testWriteCreatesDirectoryAndWritesArticleAsNdjsonLine(): void
    {
        $path = $this->temporaryOutputPath();
        $sink = new NdjsonParsedArticleSink($path);

        $sink->write($this->article());

        self::assertFileExists($path);

        $lines = $this->readLines($path);
        self::assertCount(1, $lines);

        $payload = $this->decodeLine($lines[0]);

        self::assertSame('https://example.com/news/42', $payload['externalUrl']);
        self::assertSame('bbc', $payload['sourceCode']);
        self::assertSame('world', $payload['categoryCode']);
        self::assertSame('Example title', $payload['title']);
        self::assertSame('Example content.', $payload['content']);
        self::assertSame('2026-04-26T10:15:00+00:00', $payload['publishedAt']);
        self::assertSame('Jane Doe', $payload['author']);
        self::assertSame(['language' => 'en'], $payload['metadata']);
        self::assertIsString($payload['parsedAt']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $payload['parsedAt']));
    }

    public function testWriteAppendsArticleLines(): void
    {
        $path = $this->temporaryOutputPath();
        $sink = new NdjsonParsedArticleSink($path);

        $sink->write($this->article(title: 'First title'));
        $sink->write($this->article(title: 'Second title'));

        $lines = $this->readLines($path);

        self::assertCount(2, $lines);
        self::assertSame('First title', $this->decodeLine($lines[0])['title']);
        self::assertSame('Second title', $this->decodeLine($lines[1])['title']);
    }

    private function article(string $title = 'Example title'): ParsedArticle
    {
        return new ParsedArticle(
            'https://example.com/news/42',
            'bbc',
            'world',
            $title,
            'Example content.',
            new \DateTimeImmutable('2026-04-26T10:15:00+00:00'),
            'Jane Doe',
            ['language' => 'en'],
        );
    }

    private function temporaryOutputPath(): string
    {
        return sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/articles.ndjson';
    }

    /**
     * @return list<string>
     */
    private function readLines(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return array_values(array_filter(explode("\n", $contents), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeLine(string $line): array
    {
        $payload = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}

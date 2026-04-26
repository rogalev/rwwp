<?php

declare(strict_types=1);

namespace App\Tests\Status;

use App\Status\ParserRunStatusReader;
use PHPUnit\Framework\TestCase;

final class ParserRunStatusReaderTest extends TestCase
{
    public function testReadReturnsStatusPayload(): void
    {
        $path = $this->temporaryStatusPath();
        $this->writeFile($path, '{"sourceCode":"bbc","found":3}');

        $payload = (new ParserRunStatusReader($path))->read();

        self::assertSame('bbc', $payload['sourceCode']);
        self::assertSame(3, $payload['found']);
    }

    public function testReadFailsWhenStatusFileDoesNotExist(): void
    {
        $path = $this->temporaryStatusPath();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Parser status file "%s" does not exist.', $path));

        (new ParserRunStatusReader($path))->read();
    }

    public function testReadFailsWhenStatusJsonIsNotObject(): void
    {
        $path = $this->temporaryStatusPath();
        $this->writeFile($path, '[]');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(sprintf('Parser status file "%s" must contain a JSON object.', $path));

        (new ParserRunStatusReader($path))->read();
    }

    private function temporaryStatusPath(): string
    {
        return sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/status/parser-run.json';
    }

    private function writeFile(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create test status directory "%s".', $directory));
        }

        file_put_contents($path, $contents);
    }
}

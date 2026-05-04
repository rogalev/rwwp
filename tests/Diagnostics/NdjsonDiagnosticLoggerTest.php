<?php

declare(strict_types=1);

namespace App\Tests\Diagnostics;

use App\Diagnostics\NdjsonDiagnosticLogger;
use PHPUnit\Framework\TestCase;

final class NdjsonDiagnosticLoggerTest extends TestCase
{
    public function testWritesNdjsonEventAndRemovesSensitiveFields(): void
    {
        $path = sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/diagnostic.ndjson';
        $logger = new NdjsonDiagnosticLogger(enabled: true, path: $path);

        $logger->log('main_api.request', [
            'url' => 'https://main.example.com/api/parser/v1/raw-articles',
            'Authorization' => 'Bearer secret',
            'apiKey' => 'secret',
            'rawHtml' => '<html>secret</html>',
            'nested' => [
                'api_key' => 'secret',
                'safe' => 'value',
            ],
        ]);

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $payload = json_decode(trim($contents), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('main_api.request', $payload['event']);
        self::assertSame('https://main.example.com/api/parser/v1/raw-articles', $payload['url']);
        self::assertArrayNotHasKey('Authorization', $payload);
        self::assertArrayNotHasKey('apiKey', $payload);
        self::assertArrayNotHasKey('rawHtml', $payload);
        self::assertSame(['safe' => 'value'], $payload['nested']);
    }

    public function testDoesNotWriteWhenDisabled(): void
    {
        $path = sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/diagnostic.ndjson';
        $logger = new NdjsonDiagnosticLogger(enabled: false, path: $path);

        $logger->log('main_api.request', ['url' => 'https://main.example.com']);

        self::assertFileDoesNotExist($path);
    }
}

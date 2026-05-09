<?php

declare(strict_types=1);

namespace App\Tests\MainApi;

use App\MainApi\ImageDownloadTask;
use App\MainApi\MainApiImageDownloadTaskClient;
use App\MainApi\MainApiRequestFailed;
use App\Tests\Support\NullDiagnosticLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MainApiImageDownloadTaskClientTest extends TestCase
{
    public function testClaimsImageDownloadTasks(): void
    {
        $response = new MockResponse(json_encode([
            'items' => [
                [
                    'id' => '019f0000-0000-7000-8000-000000000001',
                    'sourceName' => 'BBC / World',
                    'externalUrl' => 'https://example.com/news/1',
                    'imageUrl' => 'https://example.com/image.jpg',
                    'altText' => 'Main image',
                    'timeoutSeconds' => 20,
                    'maxBytes' => 5242880,
                ],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        $client = $this->client(new MockHttpClient($response));

        $tasks = $client->claim(5);

        self::assertCount(1, $tasks);
        self::assertSame('019f0000-0000-7000-8000-000000000001', $tasks[0]->id);
        self::assertSame('BBC / World', $tasks[0]->sourceName);
        self::assertSame('https://example.com/news/1', $tasks[0]->externalUrl);
        self::assertSame('https://example.com/image.jpg', $tasks[0]->imageUrl);
        self::assertSame('Main image', $tasks[0]->altText);
        self::assertSame(20, $tasks[0]->timeoutSeconds);
        self::assertSame(5242880, $tasks[0]->maxBytes);
        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame('https://main.example.com/api/parser/v1/image-download-tasks?limit=5', $response->getRequestUrl());
    }

    public function testCompletesImageDownloadTask(): void
    {
        $response = new MockResponse('{"status":"downloaded"}', ['http_code' => 200]);
        $client = $this->client(new MockHttpClient($response));
        $filePath = tempnam(sys_get_temp_dir(), 'rww-image-client-');
        self::assertIsString($filePath);
        file_put_contents($filePath, 'image-bytes');

        try {
            $client->complete($this->task(), $filePath);
        } finally {
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://main.example.com/api/parser/v1/image-download-tasks/019f0000-0000-7000-8000-000000000001/complete', $response->getRequestUrl());
        self::assertContains('Accept: application/json', $response->getRequestOptions()['headers']);
    }

    public function testSendsImageDownloadFailure(): void
    {
        $response = new MockResponse('{"status":"failed"}', ['http_code' => 200]);
        $client = $this->client(new MockHttpClient($response));

        $client->fail($this->task(), 'HTTP 403', ['finalUrl' => 'https://example.com/blocked']);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://main.example.com/api/parser/v1/image-download-tasks/019f0000-0000-7000-8000-000000000001/fail', $response->getRequestUrl());
        self::assertSame([
            'error' => 'HTTP 403',
            'context' => ['finalUrl' => 'https://example.com/blocked'],
        ], json_decode((string) $response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testFailsOnUnexpectedStatus(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('Forbidden', ['http_code' => 403])));

        $this->expectException(MainApiRequestFailed::class);
        $this->expectExceptionMessage('Main API image download tasks claim request failed with HTTP 403. Response: Forbidden');

        $client->claim(1);
    }

    private function client(MockHttpClient $httpClient): MainApiImageDownloadTaskClient
    {
        return new MainApiImageDownloadTaskClient(
            $httpClient,
            'https://main.example.com/',
            '0196a111-1111-7111-8111-111111111111',
            'parser-api-key',
            new NullDiagnosticLogger(),
        );
    }

    private function task(): ImageDownloadTask
    {
        return new ImageDownloadTask(
            id: '019f0000-0000-7000-8000-000000000001',
            sourceName: 'BBC / World',
            externalUrl: 'https://example.com/news/1',
            imageUrl: 'https://example.com/image.jpg',
            altText: null,
            timeoutSeconds: 20,
            maxBytes: 5242880,
        );
    }
}

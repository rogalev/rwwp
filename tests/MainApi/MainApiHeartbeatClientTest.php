<?php

declare(strict_types=1);

namespace App\Tests\MainApi;

use App\MainApi\MainApiHeartbeatClient;
use App\MainApi\MainApiRequestFailed;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MainApiHeartbeatClientTest extends TestCase
{
    public function testSendsHeartbeat(): void
    {
        $response = new MockResponse(json_encode(['accepted' => true], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        $client = $this->client(new MockHttpClient($response));

        $client->send(
            checkedAt: new \DateTimeImmutable('2026-05-02T10:00:00+00:00'),
            status: 'ok',
            message: 'last run completed',
            metrics: [
                'foundLinks' => 3,
                'acceptedRawArticles' => 2,
                'failedArticles' => 1,
                'httpStatusCodes' => [200 => 2],
                'transportErrors' => 0,
            ],
        );

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://main.example.com/api/parser/v1/heartbeat', $response->getRequestUrl());
        self::assertContains('Content-Type: application/json', $response->getRequestOptions()['headers']);
        self::assertContains('Accept: application/json', $response->getRequestOptions()['headers']);
        self::assertContains('X-Parser-Instance-Id: 0196a111-1111-7111-8111-111111111111', $response->getRequestOptions()['headers']);
        self::assertContains('Authorization: Bearer parser-api-key', $response->getRequestOptions()['headers']);
        self::assertSame([
            'checkedAt' => '2026-05-02T10:00:00+00:00',
            'status' => 'ok',
            'message' => 'last run completed',
            'metrics' => [
                'foundLinks' => 3,
                'acceptedRawArticles' => 2,
                'failedArticles' => 1,
                'httpStatusCodes' => ['200' => 2],
                'transportErrors' => 0,
            ],
        ], json_decode((string) $response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testFailsOnUnexpectedStatus(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('Unauthorized', ['http_code' => 401])));

        $this->expectException(MainApiRequestFailed::class);
        $this->expectExceptionMessage('Main API raw article request failed with HTTP 401. Response: Unauthorized');

        $client->send(new \DateTimeImmutable('2026-05-02T10:00:00+00:00'), 'ok', '', []);
    }

    private function client(MockHttpClient $httpClient): MainApiHeartbeatClient
    {
        return new MainApiHeartbeatClient(
            $httpClient,
            'https://main.example.com/',
            '0196a111-1111-7111-8111-111111111111',
            'parser-api-key',
        );
    }
}

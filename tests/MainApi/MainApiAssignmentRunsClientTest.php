<?php

declare(strict_types=1);

namespace App\Tests\MainApi;

use App\MainApi\AssignmentRunStats;
use App\MainApi\MainApiAssignmentRunsClient;
use App\MainApi\MainApiRequestFailed;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MainApiAssignmentRunsClientTest extends TestCase
{
    public function testSendsAssignmentRunsBatch(): void
    {
        $response = new MockResponse(json_encode(['accepted' => true, 'count' => 1], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        $client = $this->client(new MockHttpClient($response));

        $client->send(
            checkedAt: new \DateTimeImmutable('2026-05-04T10:00:00+00:00'),
            items: [
                new AssignmentRunStats(
                    assignmentId: '0196a222-2222-7222-8222-222222222222',
                    stage: 'raw_article_send',
                    status: 'ok',
                    found: 10,
                    queued: 2,
                    alreadySeen: 8,
                    sent: 1,
                    failed: 0,
                    skipped: false,
                    httpStatusCodes: [200 => 1],
                    transportErrors: 0,
                    durationMs: 120,
                ),
            ],
        );

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://main.example.com/api/parser/v1/assignment-runs', $response->getRequestUrl());
        self::assertContains('Content-Type: application/json', $response->getRequestOptions()['headers']);
        self::assertContains('Accept: application/json', $response->getRequestOptions()['headers']);
        self::assertContains('X-Parser-Instance-Id: 0196a111-1111-7111-8111-111111111111', $response->getRequestOptions()['headers']);
        self::assertContains('Authorization: Bearer parser-api-key', $response->getRequestOptions()['headers']);
        self::assertSame([
            'checkedAt' => '2026-05-04T10:00:00+00:00',
            'items' => [
                [
                    'assignmentId' => '0196a222-2222-7222-8222-222222222222',
                    'stage' => 'raw_article_send',
                    'status' => 'ok',
                    'found' => 10,
                    'queued' => 2,
                    'alreadySeen' => 8,
                    'sent' => 1,
                    'failed' => 0,
                    'skipped' => false,
                    'httpStatusCodes' => ['200' => 1],
                    'transportErrors' => 0,
                    'durationMs' => 120,
                    'lastError' => '',
                ],
            ],
        ], json_decode((string) $response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testDoesNotSendEmptyBatch(): void
    {
        $httpClient = new MockHttpClient(static function (): MockResponse {
            self::fail('HTTP request must not be sent for empty assignment runs batch.');
        });

        $this->client($httpClient)->send(new \DateTimeImmutable('2026-05-04T10:00:00+00:00'), []);

        self::assertSame(0, $httpClient->getRequestsCount());
    }

    public function testFailsOnUnexpectedStatus(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('Unauthorized', ['http_code' => 401])));

        $this->expectException(MainApiRequestFailed::class);
        $this->expectExceptionMessage('Main API assignment runs request failed with HTTP 401. Response: Unauthorized');

        $client->send(new \DateTimeImmutable('2026-05-04T10:00:00+00:00'), [
            new AssignmentRunStats(
                assignmentId: '0196a222-2222-7222-8222-222222222222',
                stage: 'listing',
                status: 'error',
                found: 0,
                queued: 0,
                alreadySeen: 0,
                sent: 0,
                failed: 1,
                skipped: false,
                httpStatusCodes: [],
                transportErrors: 1,
                durationMs: 50,
                lastError: 'Listing failed.',
            ),
        ]);
    }

    private function client(MockHttpClient $httpClient): MainApiAssignmentRunsClient
    {
        return new MainApiAssignmentRunsClient(
            $httpClient,
            'https://main.example.com/',
            '0196a111-1111-7111-8111-111111111111',
            'parser-api-key',
        );
    }
}

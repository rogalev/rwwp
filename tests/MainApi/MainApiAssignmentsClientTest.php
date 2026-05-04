<?php

declare(strict_types=1);

namespace App\Tests\MainApi;

use App\MainApi\MainApiAssignmentsClient;
use App\MainApi\MainApiRequestFailed;
use App\Tests\Support\NullDiagnosticLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MainApiAssignmentsClientTest extends TestCase
{
    public function testListsAssignments(): void
    {
        $response = new MockResponse(json_encode([
            'items' => [
                [
                    'assignmentId' => '0196a222-2222-7222-8222-222222222222',
                    'source' => [
                        'id' => '0196a111-1111-7111-8111-111111111111',
                        'displayName' => 'BBC',
                    ],
                    'processing' => [
                        'listingMode' => 'rss',
                        'listingUrl' => 'https://feeds.bbci.co.uk/news/world/rss.xml',
                        'articleMode' => 'html',
                        'listingCheckIntervalSeconds' => 300,
                        'articleFetchIntervalSeconds' => 10,
                        'requestTimeoutSeconds' => 15,
                        'config' => ['titleSelector' => 'h1'],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        $client = $this->client(new MockHttpClient($response));

        $assignments = $client->list();

        self::assertCount(1, $assignments);
        self::assertSame('0196a222-2222-7222-8222-222222222222', $assignments[0]->assignmentId);
        self::assertSame('0196a111-1111-7111-8111-111111111111', $assignments[0]->sourceId);
        self::assertSame('BBC', $assignments[0]->sourceDisplayName);
        self::assertSame('rss', $assignments[0]->listingMode);
        self::assertSame('https://feeds.bbci.co.uk/news/world/rss.xml', $assignments[0]->listingUrl);
        self::assertSame('html', $assignments[0]->articleMode);
        self::assertSame(300, $assignments[0]->listingCheckIntervalSeconds);
        self::assertSame(10, $assignments[0]->articleFetchIntervalSeconds);
        self::assertSame(15, $assignments[0]->requestTimeoutSeconds);
        self::assertSame(['titleSelector' => 'h1'], $assignments[0]->config);

        self::assertSame('GET', $response->getRequestMethod());
        self::assertSame('https://main.example.com/api/parser/v1/assignments', $response->getRequestUrl());
        self::assertContains('Accept: application/json', $response->getRequestOptions()['headers']);
        self::assertContains('X-Parser-Instance-Id: 0196a111-1111-7111-8111-111111111111', $response->getRequestOptions()['headers']);
        self::assertContains('Authorization: Bearer parser-api-key', $response->getRequestOptions()['headers']);
    }

    public function testReturnsEmptyList(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('{"items":[]}', ['http_code' => 200])));

        self::assertSame([], $client->list());
    }

    public function testFailsOnUnexpectedStatus(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('Unauthorized', ['http_code' => 401])));

        $this->expectException(MainApiRequestFailed::class);
        $this->expectExceptionMessage('Main API raw article request failed with HTTP 401. Response: Unauthorized');

        $client->list();
    }

    public function testFailsOnInvalidResponseStructure(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('{"items":{"broken":true}}', ['http_code' => 200])));

        $this->expectException(MainApiRequestFailed::class);
        $this->expectExceptionMessage('Main API assignments response field "items" must be an array.');

        $client->list();
    }

    private function client(MockHttpClient $httpClient): MainApiAssignmentsClient
    {
        return new MainApiAssignmentsClient(
            $httpClient,
            'https://main.example.com/',
            '0196a111-1111-7111-8111-111111111111',
            'parser-api-key',
            new NullDiagnosticLogger(),
        );
    }
}

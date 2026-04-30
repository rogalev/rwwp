<?php

declare(strict_types=1);

namespace App\Tests\MainApi;

use App\MainApi\MainApiRawArticleClient;
use App\MainApi\MainApiRequestFailed;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MainApiRawArticleClientTest extends TestCase
{
    public function testSendsRawArticleAndReturnsCreatedResult(): void
    {
        $response = new MockResponse(json_encode([
            'id' => '0196a333-3333-7333-8333-333333333333',
            'created' => true,
            'externalUrl' => 'https://example.com/news/1',
            'contentHash' => 'content-hash',
        ], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        $client = $this->client(new MockHttpClient($response));

        $result = $client->send(
            '0196a222-2222-7222-8222-222222222222',
            'https://example.com/news/1',
            '<html>Article</html>',
            200,
            new \DateTimeImmutable('2026-04-30T10:00:00+00:00'),
        );

        self::assertSame('0196a333-3333-7333-8333-333333333333', $result->id);
        self::assertTrue($result->created);
        self::assertSame('https://example.com/news/1', $result->externalUrl);
        self::assertSame('content-hash', $result->contentHash);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://main.example.com/api/parser/v1/raw-articles', $response->getRequestUrl());
        self::assertContains('Content-Type: application/json', $response->getRequestOptions()['headers']);
        self::assertContains('Accept: application/json', $response->getRequestOptions()['headers']);
        self::assertContains('X-Parser-Instance-Id: 0196a111-1111-7111-8111-111111111111', $response->getRequestOptions()['headers']);
        self::assertContains('Authorization: Bearer parser-api-key', $response->getRequestOptions()['headers']);
        self::assertSame([
            'assignmentId' => '0196a222-2222-7222-8222-222222222222',
            'externalUrl' => 'https://example.com/news/1',
            'rawHtml' => '<html>Article</html>',
            'httpStatusCode' => 200,
            'fetchedAt' => '2026-04-30T10:00:00+00:00',
        ], json_decode((string) $response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testReturnsExistingResult(): void
    {
        $response = new MockResponse(json_encode([
            'id' => '0196a333-3333-7333-8333-333333333333',
            'created' => false,
            'externalUrl' => 'https://example.com/news/1',
            'contentHash' => 'content-hash',
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        $client = $this->client(new MockHttpClient($response));

        $result = $client->send(
            '0196a222-2222-7222-8222-222222222222',
            'https://example.com/news/1',
            '<html>Article</html>',
            200,
            new \DateTimeImmutable('2026-04-30T10:00:00+00:00'),
        );

        self::assertFalse($result->created);
    }

    public function testFailsOnUnexpectedStatus(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('Forbidden', ['http_code' => 403])));

        $this->expectException(MainApiRequestFailed::class);
        $this->expectExceptionMessage('Main API raw article request failed with HTTP 403. Response: Forbidden');

        $client->send(
            '0196a222-2222-7222-8222-222222222222',
            'https://example.com/news/1',
            '<html>Article</html>',
            200,
            new \DateTimeImmutable('2026-04-30T10:00:00+00:00'),
        );
    }

    private function client(MockHttpClient $httpClient): MainApiRawArticleClient
    {
        return new MainApiRawArticleClient(
            $httpClient,
            'https://main.example.com/',
            '0196a111-1111-7111-8111-111111111111',
            'parser-api-key',
        );
    }
}

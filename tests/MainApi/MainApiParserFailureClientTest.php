<?php

declare(strict_types=1);

namespace App\Tests\MainApi;

use App\MainApi\MainApiParserFailureClient;
use App\MainApi\MainApiRequestFailed;
use App\Tests\Support\NullDiagnosticLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MainApiParserFailureClientTest extends TestCase
{
    public function testSendsParserFailure(): void
    {
        $response = new MockResponse(json_encode([
            'accepted' => true,
            'id' => '0196a333-3333-7333-8333-333333333333',
        ], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        $client = $this->client(new MockHttpClient($response));

        $client->send(
            assignmentId: '0196a222-2222-7222-8222-222222222222',
            stage: 'article_fetch',
            message: 'Network timeout.',
            context: [
                'externalUrl' => 'https://example.com/news/1',
                'exceptionClass' => \RuntimeException::class,
            ],
            occurredAt: new \DateTimeImmutable('2026-05-02T10:00:00+00:00'),
        );

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://main.example.com/api/parser/v1/failures', $response->getRequestUrl());
        self::assertContains('Content-Type: application/json', $response->getRequestOptions()['headers']);
        self::assertContains('Accept: application/json', $response->getRequestOptions()['headers']);
        self::assertContains('X-Parser-Instance-Id: 0196a111-1111-7111-8111-111111111111', $response->getRequestOptions()['headers']);
        self::assertContains('Authorization: Bearer parser-api-key', $response->getRequestOptions()['headers']);
        self::assertSame([
            'assignmentId' => '0196a222-2222-7222-8222-222222222222',
            'stage' => 'article_fetch',
            'message' => 'Network timeout.',
            'context' => [
                'externalUrl' => 'https://example.com/news/1',
                'exceptionClass' => \RuntimeException::class,
            ],
            'occurredAt' => '2026-05-02T10:00:00+00:00',
        ], json_decode((string) $response->getRequestOptions()['body'], true, flags: JSON_THROW_ON_ERROR));
    }

    public function testFailsOnUnexpectedStatus(): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse('Bad payload', ['http_code' => 400])));

        $this->expectException(MainApiRequestFailed::class);
        $this->expectExceptionMessage('Main API parser failure request failed with HTTP 400. Response: Bad payload');

        $client->send(
            assignmentId: '0196a222-2222-7222-8222-222222222222',
            stage: 'article_fetch',
            message: 'Network timeout.',
            context: [],
            occurredAt: new \DateTimeImmutable('2026-05-02T10:00:00+00:00'),
        );
    }

    private function client(MockHttpClient $httpClient): MainApiParserFailureClient
    {
        return new MainApiParserFailureClient(
            $httpClient,
            'https://main.example.com/',
            '0196a111-1111-7111-8111-111111111111',
            'parser-api-key',
            new NullDiagnosticLogger(),
        );
    }
}

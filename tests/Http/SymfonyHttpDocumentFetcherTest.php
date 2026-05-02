<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\DocumentFetchException;
use App\Http\SymfonyHttpDocumentFetcher;
use App\Http\UserAgentProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SymfonyHttpDocumentFetcherTest extends TestCase
{
    public function testFetchRetriesTemporaryServerErrorAndReturnsSuccessfulDocument(): void
    {
        $firstResponse = new MockResponse('Temporary error', ['http_code' => 500]);
        $secondResponse = new MockResponse('Article HTML', [
            'http_code' => 200,
            'response_headers' => ['content-type: text/html'],
        ]);
        $fetcher = $this->fetcher(new MockHttpClient([$firstResponse, $secondResponse]));

        $document = $fetcher->fetch('https://example.com/news/42');

        self::assertSame(200, $document->statusCode);
        self::assertSame('Article HTML', $document->content);
        self::assertSame('text/html', $document->contentType);
        self::assertSame('PHPUnit User-Agent', $document->userAgent);
    }

    public function testFetchDoesNotRetryClientError(): void
    {
        $response = new MockResponse('Not found', ['http_code' => 404]);
        $fetcher = $this->fetcher(new MockHttpClient([$response]));

        $this->expectException(DocumentFetchException::class);
        $this->expectExceptionMessage('Unexpected HTTP status 404 while fetching "https://example.com/news/42".');

        $fetcher->fetch('https://example.com/news/42');
    }

    public function testFetchFailsAfterRetryAttemptsAreExhausted(): void
    {
        $fetcher = $this->fetcher(new MockHttpClient([
            new MockResponse('Temporary error', ['http_code' => 500]),
            new MockResponse('Still temporary error', ['http_code' => 502]),
        ]));

        $this->expectException(DocumentFetchException::class);
        $this->expectExceptionMessage('Unexpected HTTP status 502 while fetching "https://example.com/news/42".');

        $fetcher->fetch('https://example.com/news/42');
    }

    public function testFetchMergesCustomHeadersWithDefaults(): void
    {
        $response = new MockResponse('Article HTML', ['http_code' => 200]);
        $fetcher = $this->fetcher(new MockHttpClient($response));

        $fetcher->fetch('https://example.com/news/42', [
            'Accept-Language' => 'ru-RU,ru;q=0.9',
            'Referer' => 'https://example.com/',
        ]);

        $headers = $response->getRequestOptions()['headers'];
        self::assertContains('User-Agent: PHPUnit User-Agent', $headers);
        self::assertContains('Accept-Language: ru-RU,ru;q=0.9', $headers);
        self::assertContains('Referer: https://example.com/', $headers);
    }

    private function fetcher(MockHttpClient $httpClient): SymfonyHttpDocumentFetcher
    {
        return new SymfonyHttpDocumentFetcher(
            $httpClient,
            new FixedUserAgentProvider(),
            timeout: 10,
            maxDuration: 30,
            retryAttempts: 2,
            retryDelayMs: 0,
            minDelayMs: 0,
            jitterMs: 0,
        );
    }
}

final readonly class FixedUserAgentProvider implements UserAgentProviderInterface
{
    public function next(): string
    {
        return 'PHPUnit User-Agent';
    }
}

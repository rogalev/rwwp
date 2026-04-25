<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class SymfonyHttpDocumentFetcher implements DocumentFetcherInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private UserAgentProviderInterface $userAgentProvider,
        private float $timeout,
        private float $maxDuration,
    ) {
    }

    public function fetch(string $url): FetchedDocument
    {
        $userAgent = $this->userAgentProvider->next();
        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'User-Agent' => $userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
            'timeout' => $this->timeout,
            'max_duration' => $this->maxDuration,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            throw DocumentFetchException::forUnexpectedStatus($url, $statusCode);
        }

        $headers = $response->getHeaders(false);

        return new FetchedDocument(
            url: $url,
            statusCode: $statusCode,
            content: $response->getContent(),
            contentType: $headers['content-type'][0] ?? null,
            userAgent: $userAgent,
            fetchedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }
}

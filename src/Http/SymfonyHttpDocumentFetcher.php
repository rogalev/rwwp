<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final readonly class SymfonyHttpDocumentFetcher implements DocumentFetcherInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private UserAgentProviderInterface $userAgentProvider,
        private float $timeout,
        private float $maxDuration,
        private int $retryAttempts,
        private int $retryDelayMs,
    ) {
    }

    public function fetch(string $url): FetchedDocument
    {
        $userAgent = $this->userAgentProvider->next();
        $lastTransportException = null;

        for ($attempt = 1; $attempt <= $this->maxAttempts(); ++$attempt) {
            try {
                $response = $this->request($url, $userAgent);
                $statusCode = $response->getStatusCode();

                if ($statusCode >= 200 && $statusCode < 300) {
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

                if (!$this->shouldRetryStatus($statusCode) || $attempt === $this->maxAttempts()) {
                    throw DocumentFetchException::forUnexpectedStatus($url, $statusCode);
                }
            } catch (TransportExceptionInterface $exception) {
                $lastTransportException = $exception;

                if ($attempt === $this->maxAttempts()) {
                    throw DocumentFetchException::forTransportError($url, $exception);
                }
            }

            $this->sleepBeforeRetry();
        }

        throw DocumentFetchException::forTransportError($url, $lastTransportException);
    }

    private function request(string $url, string $userAgent): ResponseInterface
    {
        return $this->httpClient->request('GET', $url, [
            'headers' => [
                'User-Agent' => $userAgent,
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
            'timeout' => $this->timeout,
            'max_duration' => $this->maxDuration,
        ]);
    }

    private function maxAttempts(): int
    {
        return max(1, $this->retryAttempts);
    }

    private function shouldRetryStatus(int $statusCode): bool
    {
        return $statusCode === 429 || ($statusCode >= 500 && $statusCode <= 599);
    }

    private function sleepBeforeRetry(): void
    {
        if ($this->retryDelayMs <= 0) {
            return;
        }

        usleep($this->retryDelayMs * 1000);
    }
}

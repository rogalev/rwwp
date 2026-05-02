<?php

declare(strict_types=1);

namespace App\MainApi;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MainApiHeartbeatClient implements MainApiHeartbeatSenderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $parserInstanceId,
        private string $apiKey,
    ) {
    }

    /**
     * @param array<string, mixed> $metrics
     */
    public function send(
        \DateTimeImmutable $checkedAt,
        string $status,
        string $message,
        array $metrics,
    ): void {
        $response = $this->httpClient->request('POST', $this->url('/api/parser/v1/heartbeat'), [
            'headers' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Parser-Instance-Id: '.$this->parserInstanceId,
                'Authorization: Bearer '.$this->apiKey,
            ],
            'body' => json_encode([
                'checkedAt' => $checkedAt->format(\DateTimeInterface::ATOM),
                'status' => $status,
                'message' => $message,
                'metrics' => $metrics,
            ], JSON_THROW_ON_ERROR),
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getContent(false);
        if ($statusCode !== 200) {
            throw MainApiRequestFailed::forUnexpectedStatus($statusCode, $body);
        }
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}

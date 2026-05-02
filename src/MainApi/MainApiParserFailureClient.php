<?php

declare(strict_types=1);

namespace App\MainApi;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MainApiParserFailureClient implements MainApiParserFailureSenderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $parserInstanceId,
        private string $apiKey,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function send(
        string $assignmentId,
        string $stage,
        string $message,
        array $context,
        \DateTimeImmutable $occurredAt,
    ): void {
        $response = $this->httpClient->request('POST', $this->url('/api/parser/v1/failures'), [
            'headers' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Parser-Instance-Id: '.$this->parserInstanceId,
                'Authorization: Bearer '.$this->apiKey,
            ],
            'body' => json_encode([
                'assignmentId' => $assignmentId,
                'stage' => $stage,
                'message' => $message,
                'context' => $context,
                'occurredAt' => $occurredAt->format(\DateTimeInterface::ATOM),
            ], JSON_THROW_ON_ERROR),
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getContent(false);
        if ($statusCode !== 201) {
            throw MainApiRequestFailed::forUnexpectedStatus($statusCode, $body, 'parser failure');
        }
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}

<?php

declare(strict_types=1);

namespace App\MainApi;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MainApiRawArticleClient implements MainApiRawArticleSenderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $parserInstanceId,
        private string $apiKey,
    ) {
    }

    public function send(
        string $assignmentId,
        string $externalUrl,
        string $rawHtml,
        int $httpStatusCode,
        \DateTimeImmutable $fetchedAt,
    ): SendRawArticleResult {
        $response = $this->httpClient->request('POST', $this->url('/api/parser/v1/raw-articles'), [
            'headers' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Parser-Instance-Id: '.$this->parserInstanceId,
                'Authorization: Bearer '.$this->apiKey,
            ],
            'body' => json_encode([
                'assignmentId' => $assignmentId,
                'externalUrl' => $externalUrl,
                'rawHtml' => $rawHtml,
                'httpStatusCode' => $httpStatusCode,
                'fetchedAt' => $fetchedAt->format(\DateTimeInterface::ATOM),
            ], JSON_THROW_ON_ERROR),
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getContent(false);
        if ($statusCode !== 200 && $statusCode !== 201) {
            throw MainApiRequestFailed::forUnexpectedStatus($statusCode, $body);
        }

        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($payload)) {
            throw new MainApiRequestFailed('Main API raw article response must be a JSON object.');
        }

        return new SendRawArticleResult(
            $this->readString($payload, 'id'),
            $this->readBool($payload, 'created'),
            $this->readString($payload, 'externalUrl'),
            $this->readString($payload, 'contentHash'),
        );
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readString(array $payload, string $field): string
    {
        if (!isset($payload[$field]) || !\is_string($payload[$field]) || $payload[$field] === '') {
            throw new MainApiRequestFailed('Main API raw article response field "'.$field.'" must be a non-empty string.');
        }

        return $payload[$field];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readBool(array $payload, string $field): bool
    {
        if (!isset($payload[$field]) || !\is_bool($payload[$field])) {
            throw new MainApiRequestFailed('Main API raw article response field "'.$field.'" must be boolean.');
        }

        return $payload[$field];
    }
}

<?php

declare(strict_types=1);

namespace App\MainApi;

use App\Diagnostics\DiagnosticLoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MainApiRawArticleClient implements MainApiRawArticleSenderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $parserInstanceId,
        private string $apiKey,
        private DiagnosticLoggerInterface $diagnostics,
    ) {
    }

    public function send(
        string $assignmentId,
        string $externalUrl,
        string $rawHtml,
        int $httpStatusCode,
        \DateTimeImmutable $fetchedAt,
    ): SendRawArticleResult {
        $url = $this->url('/api/parser/v1/raw-articles');
        $this->diagnostics->log('main_api.request', [
            'operation' => 'raw_article',
            'method' => 'POST',
            'url' => $url,
            'assignmentId' => $assignmentId,
            'externalUrl' => $externalUrl,
            'rawHtmlLength' => strlen($rawHtml),
            'httpStatusCode' => $httpStatusCode,
            'fetchedAt' => $fetchedAt->format(\DateTimeInterface::ATOM),
        ]);

        $response = $this->httpClient->request('POST', $url, [
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
        $this->diagnostics->log('main_api.response', [
            'operation' => 'raw_article',
            'method' => 'POST',
            'url' => $url,
            'statusCode' => $statusCode,
            'bodyPreview' => $body,
        ]);

        if ($statusCode !== 202) {
            throw MainApiRequestFailed::forUnexpectedStatus($statusCode, $body);
        }

        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($payload)) {
            throw new MainApiRequestFailed('Main API raw article response must be a JSON object.');
        }

        return new SendRawArticleResult(
            $this->readString($payload, 'jobId'),
            $this->readBool($payload, 'accepted'),
            $this->readString($payload, 'externalUrl'),
            $this->readString($payload, 'status'),
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

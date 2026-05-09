<?php

declare(strict_types=1);

namespace App\MainApi;

use App\Diagnostics\DiagnosticLoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MainApiImageDownloadTaskClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $parserInstanceId,
        private string $apiKey,
        private DiagnosticLoggerInterface $diagnostics,
    ) {
    }

    /**
     * @return list<ImageDownloadTask>
     */
    public function claim(int $limit): array
    {
        $url = $this->url('/api/parser/v1/image-download-tasks?limit='.max(1, $limit));
        $response = $this->httpClient->request('GET', $url, [
            'headers' => $this->headers(),
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getContent(false);

        $this->diagnostics->log('main_api.response', [
            'operation' => 'image_download_tasks_claim',
            'method' => 'GET',
            'url' => $url,
            'statusCode' => $statusCode,
            'bodyPreview' => $body,
        ]);

        if ($statusCode !== 200) {
            throw MainApiRequestFailed::forUnexpectedStatus($statusCode, $body, 'image download tasks claim');
        }

        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($payload) || !isset($payload['items']) || !\is_array($payload['items'])) {
            throw new MainApiRequestFailed('Main API image download tasks response field "items" must be an array.');
        }

        return array_map(fn (mixed $item): ImageDownloadTask => $this->parseTask($item), $payload['items']);
    }

    public function complete(ImageDownloadTask $task, string $filePath): void
    {
        $url = $this->url('/api/parser/v1/image-download-tasks/'.$task->id.'/complete');
        $response = $this->httpClient->request('POST', $url, [
            'headers' => $this->headers(),
            'body' => [
                'file' => fopen($filePath, 'rb'),
            ],
        ]);

        $this->ensureSuccess($response->getStatusCode(), $response->getContent(false), 'image download task complete');
    }

    /**
     * @param array<string, mixed> $context
     */
    public function fail(ImageDownloadTask $task, string $error, array $context = []): void
    {
        $url = $this->url('/api/parser/v1/image-download-tasks/'.$task->id.'/fail');
        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                ...$this->headers(),
                'Content-Type: application/json',
            ],
            'body' => json_encode([
                'error' => $error,
                'context' => $context,
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->ensureSuccess($response->getStatusCode(), $response->getContent(false), 'image download task fail');
    }

    private function ensureSuccess(int $statusCode, string $body, string $operation): void
    {
        $this->diagnostics->log('main_api.response', [
            'operation' => $operation,
            'statusCode' => $statusCode,
            'bodyPreview' => $body,
        ]);

        if ($statusCode !== 200) {
            throw MainApiRequestFailed::forUnexpectedStatus($statusCode, $body, $operation);
        }
    }

    /**
     * @return list<string>
     */
    private function headers(): array
    {
        return [
            'Accept: application/json',
            'X-Parser-Instance-Id: '.$this->parserInstanceId,
            'Authorization: Bearer '.$this->apiKey,
        ];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    private function parseTask(mixed $item): ImageDownloadTask
    {
        if (!\is_array($item)) {
            throw new MainApiRequestFailed('Main API image download task item must be an object.');
        }

        return new ImageDownloadTask(
            id: $this->readString($item, 'id'),
            sourceName: $this->readString($item, 'sourceName'),
            externalUrl: $this->readString($item, 'externalUrl'),
            imageUrl: $this->readString($item, 'imageUrl'),
            altText: $this->readOptionalString($item, 'altText'),
            timeoutSeconds: $this->readInt($item, 'timeoutSeconds'),
            maxBytes: $this->readInt($item, 'maxBytes'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readString(array $payload, string $field): string
    {
        if (!isset($payload[$field]) || !\is_string($payload[$field]) || trim($payload[$field]) === '') {
            throw new MainApiRequestFailed('Main API image download task field "'.$field.'" must be a non-empty string.');
        }

        return trim($payload[$field]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readOptionalString(array $payload, string $field): ?string
    {
        if (!isset($payload[$field]) || $payload[$field] === null) {
            return null;
        }

        if (!\is_string($payload[$field])) {
            throw new MainApiRequestFailed('Main API image download task field "'.$field.'" must be a string or null.');
        }

        $value = trim($payload[$field]);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readInt(array $payload, string $field): int
    {
        if (!isset($payload[$field]) || !\is_int($payload[$field]) || $payload[$field] <= 0) {
            throw new MainApiRequestFailed('Main API image download task field "'.$field.'" must be a positive integer.');
        }

        return $payload[$field];
    }
}

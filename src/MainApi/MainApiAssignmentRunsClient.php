<?php

declare(strict_types=1);

namespace App\MainApi;

use App\Diagnostics\DiagnosticLoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MainApiAssignmentRunsClient implements MainApiAssignmentRunsSenderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $parserInstanceId,
        private string $apiKey,
        private DiagnosticLoggerInterface $diagnostics,
    ) {
    }

    public function send(\DateTimeImmutable $checkedAt, array $items): void
    {
        if ($items === []) {
            return;
        }

        $url = $this->url('/api/parser/v1/assignment-runs');
        $this->diagnostics->log('main_api.request', [
            'operation' => 'assignment_runs',
            'method' => 'POST',
            'url' => $url,
            'itemsCount' => count($items),
        ]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-Parser-Instance-Id: '.$this->parserInstanceId,
                'Authorization: Bearer '.$this->apiKey,
            ],
            'body' => json_encode([
                'checkedAt' => $checkedAt->format(\DateTimeInterface::ATOM),
                'items' => array_map(
                    static fn (AssignmentRunStats $item): array => [
                        'assignmentId' => $item->assignmentId,
                        'stage' => $item->stage,
                        'status' => $item->status,
                        'found' => $item->found,
                        'queued' => $item->queued,
                        'alreadySeen' => $item->alreadySeen,
                        'sent' => $item->sent,
                        'failed' => $item->failed,
                        'skipped' => $item->skipped,
                        'httpStatusCodes' => $item->httpStatusCodes,
                        'transportErrors' => $item->transportErrors,
                        'durationMs' => $item->durationMs,
                        'lastError' => $item->lastError,
                    ],
                    $items,
                ),
            ], JSON_THROW_ON_ERROR),
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getContent(false);
        $this->diagnostics->log('main_api.response', [
            'operation' => 'assignment_runs',
            'method' => 'POST',
            'url' => $url,
            'statusCode' => $statusCode,
            'bodyPreview' => $body,
        ]);

        if ($statusCode !== 201) {
            throw MainApiRequestFailed::forUnexpectedStatus($statusCode, $body, 'assignment runs');
        }
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}

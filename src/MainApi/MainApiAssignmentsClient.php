<?php

declare(strict_types=1);

namespace App\MainApi;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class MainApiAssignmentsClient implements MainApiAssignmentsProviderInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        private string $parserInstanceId,
        private string $apiKey,
    ) {
    }

    /**
     * @return list<ParserAssignment>
     */
    public function list(): array
    {
        $response = $this->httpClient->request('GET', $this->url('/api/parser/v1/assignments'), [
            'headers' => [
                'Accept: application/json',
                'X-Parser-Instance-Id: '.$this->parserInstanceId,
                'Authorization: Bearer '.$this->apiKey,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $body = $response->getContent(false);
        if ($statusCode !== 200) {
            throw MainApiRequestFailed::forUnexpectedStatus($statusCode, $body);
        }

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new MainApiRequestFailed('Main API assignments response must be valid JSON.', previous: $exception);
        }

        if (!\is_array($payload) || !isset($payload['items']) || !\is_array($payload['items']) || !array_is_list($payload['items'])) {
            throw new MainApiRequestFailed('Main API assignments response field "items" must be an array.');
        }

        $assignments = [];
        foreach ($payload['items'] as $item) {
            if (!\is_array($item)) {
                throw new MainApiRequestFailed('Main API assignment item must be an object.');
            }

            $assignments[] = $this->parseAssignment($item);
        }

        return $assignments;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function parseAssignment(array $item): ParserAssignment
    {
        $source = $this->readArray($item, 'source');
        $processing = $this->readArray($item, 'processing');

        return new ParserAssignment(
            assignmentId: $this->readString($item, 'assignmentId'),
            sourceId: $this->readString($source, 'id'),
            sourceDisplayName: $this->readString($source, 'displayName'),
            listingMode: $this->readString($processing, 'listingMode'),
            listingUrl: $this->readString($processing, 'listingUrl'),
            articleMode: $this->readString($processing, 'articleMode'),
            listingCheckIntervalSeconds: $this->readInt($processing, 'listingCheckIntervalSeconds'),
            articleFetchIntervalSeconds: $this->readInt($processing, 'articleFetchIntervalSeconds'),
            requestTimeoutSeconds: $this->readInt($processing, 'requestTimeoutSeconds'),
            config: $this->readArray($processing, 'config'),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readString(array $payload, string $field): string
    {
        if (!isset($payload[$field]) || !\is_string($payload[$field])) {
            throw new MainApiRequestFailed('Main API assignments response field "'.$field.'" must be a string.');
        }

        return $payload[$field];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function readInt(array $payload, string $field): int
    {
        if (!isset($payload[$field]) || !\is_int($payload[$field])) {
            throw new MainApiRequestFailed('Main API assignments response field "'.$field.'" must be an integer.');
        }

        return $payload[$field];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function readArray(array $payload, string $field): array
    {
        if (!isset($payload[$field]) || !\is_array($payload[$field])) {
            throw new MainApiRequestFailed('Main API assignments response field "'.$field.'" must be an object.');
        }

        return $payload[$field];
    }
}

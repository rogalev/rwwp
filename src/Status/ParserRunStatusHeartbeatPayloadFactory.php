<?php

declare(strict_types=1);

namespace App\Status;

final readonly class ParserRunStatusHeartbeatPayloadFactory
{
    /**
     * @param array<string, mixed> $status
     */
    public function create(array $status): HeartbeatPayload
    {
        return new HeartbeatPayload(
            checkedAt: $this->checkedAt($status),
            status: $this->status($status),
            message: $this->message($status),
            metrics: $this->metrics($status),
        );
    }

    /**
     * @param array<string, mixed> $status
     */
    private function checkedAt(array $status): \DateTimeImmutable
    {
        if (isset($status['checkedAt']) && \is_string($status['checkedAt']) && trim($status['checkedAt']) !== '') {
            return new \DateTimeImmutable($status['checkedAt']);
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $status
     */
    private function status(array $status): string
    {
        return $this->message($status) === '' ? 'ok' : 'error';
    }

    /**
     * @param array<string, mixed> $status
     */
    private function message(array $status): string
    {
        return isset($status['lastError']) && \is_string($status['lastError']) ? $status['lastError'] : '';
    }

    /**
     * @param array<string, mixed> $status
     *
     * @return array<string, mixed>
     */
    private function metrics(array $status): array
    {
        return [
            'durationSeconds' => $this->intValue($status['durationSeconds'] ?? null),
            'skippedAssignments' => $this->intValue($status['skippedAssignments'] ?? null),
            'foundLinks' => $this->intValue($status['found'] ?? null),
            'acceptedRawArticles' => $this->intValue($status['sent'] ?? null),
            'failedArticles' => $this->intValue($status['failed'] ?? null),
            'httpStatusCodes' => \is_array($status['httpStatusCodes'] ?? null) ? $status['httpStatusCodes'] : [],
            'transportErrors' => $this->intValue($status['transportErrors'] ?? null),
            'stage' => $this->stringValue($status['stage'] ?? null),
        ];
    }

    private function intValue(mixed $value): int
    {
        return \is_int($value) ? $value : 0;
    }

    private function stringValue(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}

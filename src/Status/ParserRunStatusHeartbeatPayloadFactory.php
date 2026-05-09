<?php

declare(strict_types=1);

namespace App\Status;

final readonly class ParserRunStatusHeartbeatPayloadFactory
{
    private const AGENT_VERSION = '0.1.0';

    public function __construct(
        private ?RuntimeMetricsCollector $runtimeMetricsCollector = null,
    ) {
    }

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
        $message = $this->message($status);
        $assignments = $this->intValue($status['assignments'] ?? null);
        $skippedAssignments = $this->intValue($status['skippedAssignments'] ?? null);
        $failedArticles = $this->intValue($status['failed'] ?? null);
        $transportErrors = $this->intValue($status['transportErrors'] ?? null);
        $usefulWork = $this->intValue($status['found'] ?? null)
            + $this->intValue($status['queued'] ?? null)
            + $this->intValue($status['sent'] ?? null);

        if ($assignments > 0 && $assignments === $skippedAssignments && $message === '') {
            return 'idle';
        }

        if ($message !== '') {
            return $usefulWork > 0 ? 'partial' : 'error';
        }

        if ($failedArticles > 0) {
            return $usefulWork > 0 ? 'partial' : 'error';
        }

        if ($transportErrors > 0 || $this->hasDegradedHttpStatus($status)) {
            return 'degraded';
        }

        return 'ok';
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
        return array_merge([
            'durationSeconds' => $this->intValue($status['durationSeconds'] ?? null),
            'processedAssignments' => $this->intValue($status['processedAssignments'] ?? null),
            'totalAssignments' => $this->intValue($status['totalAssignments'] ?? ($status['assignments'] ?? null)),
            'timedOutAssignments' => $this->intValue($status['timedOutAssignments'] ?? null),
            'currentAssignmentId' => $this->stringValue($status['currentAssignmentId'] ?? null),
            'currentSource' => $this->stringValue($status['currentSource'] ?? null),
            'lastHeartbeatAt' => $this->stringValue($status['lastHeartbeatAt'] ?? null),
            'skippedAssignments' => $this->intValue($status['skippedAssignments'] ?? null),
            'foundLinks' => $this->intValue($status['found'] ?? null),
            'queuedArticles' => $this->intValue($status['queued'] ?? null),
            'acceptedRawArticles' => $this->intValue($status['sent'] ?? null),
            'failedArticles' => $this->intValue($status['failed'] ?? null),
            'httpStatusCodes' => \is_array($status['httpStatusCodes'] ?? null) ? $status['httpStatusCodes'] : [],
            'transportErrors' => $this->intValue($status['transportErrors'] ?? null),
            'stage' => $this->stringValue($status['stage'] ?? null),
            'agentVersion' => $this->envString('PARSER_AGENT_VERSION', self::AGENT_VERSION),
            'phpVersion' => PHP_VERSION,
            'gitCommit' => $this->envString('PARSER_AGENT_GIT_COMMIT', ''),
            'capabilities' => ['rss_listing', 'html_listing', 'raw_html_delivery'],
        ], $this->runtimeMetricsCollector?->collect() ?? []);
    }

    private function intValue(mixed $value): int
    {
        return \is_int($value) ? $value : 0;
    }

    private function stringValue(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    private function envString(string $name, string $default): string
    {
        $value = getenv($name);

        return \is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * @param array<string, mixed> $status
     */
    private function hasDegradedHttpStatus(array $status): bool
    {
        if (!\is_array($status['httpStatusCodes'] ?? null)) {
            return false;
        }

        foreach (array_keys($status['httpStatusCodes']) as $statusCode) {
            $statusCode = (int) $statusCode;
            if ($statusCode === 403 || $statusCode === 429 || $statusCode >= 500) {
                return true;
            }
        }

        return false;
    }
}

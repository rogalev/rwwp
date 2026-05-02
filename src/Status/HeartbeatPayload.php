<?php

declare(strict_types=1);

namespace App\Status;

final readonly class HeartbeatPayload
{
    /**
     * @param array<string, mixed> $metrics
     */
    public function __construct(
        public \DateTimeImmutable $checkedAt,
        public string $status,
        public string $message,
        public array $metrics,
    ) {
    }
}

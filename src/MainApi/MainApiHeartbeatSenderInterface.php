<?php

declare(strict_types=1);

namespace App\MainApi;

interface MainApiHeartbeatSenderInterface
{
    /**
     * @param array<string, mixed> $metrics
     */
    public function send(
        \DateTimeImmutable $checkedAt,
        string $status,
        string $message,
        array $metrics,
    ): void;
}

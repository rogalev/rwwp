<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\MainApi\MainApiHeartbeatSenderInterface;

final class NullHeartbeatSender implements MainApiHeartbeatSenderInterface
{
    /**
     * @param array<string, mixed> $metrics
     */
    public function send(
        \DateTimeImmutable $checkedAt,
        string $status,
        string $message,
        array $metrics,
    ): void {
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\MainApi\MainApiParserFailureSenderInterface;

final class NullParserFailureSender implements MainApiParserFailureSenderInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function send(
        string $assignmentId,
        string $stage,
        string $message,
        array $context,
        \DateTimeImmutable $occurredAt,
    ): void {
    }
}

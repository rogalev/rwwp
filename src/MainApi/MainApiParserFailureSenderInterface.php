<?php

declare(strict_types=1);

namespace App\MainApi;

/**
 * Sends diagnostic parser failures to main on a best-effort basis.
 */
interface MainApiParserFailureSenderInterface
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
    ): void;
}

<?php

declare(strict_types=1);

namespace App\Diagnostics;

interface DiagnosticLoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(string $event, array $context = []): void;
}

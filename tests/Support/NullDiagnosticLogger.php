<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Diagnostics\DiagnosticLoggerInterface;

final class NullDiagnosticLogger implements DiagnosticLoggerInterface
{
    public function log(string $event, array $context = []): void
    {
    }
}

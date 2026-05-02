<?php

declare(strict_types=1);

namespace App\MainApi;

final class MainApiRequestFailed extends \RuntimeException
{
    public static function forUnexpectedStatus(
        int $statusCode,
        string $responseBody,
        string $operation = 'raw article',
    ): self {
        $message = sprintf('Main API %s request failed with HTTP %d.', $operation, $statusCode);
        if ($responseBody !== '') {
            $message .= ' Response: '.$responseBody;
        }

        return new self($message);
    }
}

<?php

declare(strict_types=1);

namespace App\MainApi;

final class MainApiRequestFailed extends \RuntimeException
{
    public static function forUnexpectedStatus(int $statusCode, string $responseBody): self
    {
        $message = sprintf('Main API raw article request failed with HTTP %d.', $statusCode);
        if ($responseBody !== '') {
            $message .= ' Response: '.$responseBody;
        }

        return new self($message);
    }
}

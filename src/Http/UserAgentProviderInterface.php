<?php

declare(strict_types=1);

namespace App\Http;

interface UserAgentProviderInterface
{
    public function next(): string;
}

<?php

declare(strict_types=1);

namespace App\Http;

final readonly class RotatingUserAgentProvider implements UserAgentProviderInterface
{
    /**
     * @param list<string> $userAgents
     */
    public function __construct(
        private array $userAgents,
    ) {
        if ($this->userAgents === []) {
            throw new \InvalidArgumentException('At least one User-Agent must be configured.');
        }
    }

    public function next(): string
    {
        return $this->userAgents[array_rand($this->userAgents)];
    }
}

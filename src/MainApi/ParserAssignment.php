<?php

declare(strict_types=1);

namespace App\MainApi;

final readonly class ParserAssignment
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public string $assignmentId,
        public string $sourceId,
        public string $sourceDisplayName,
        public string $listingMode,
        public string $listingUrl,
        public string $articleMode,
        public int $listingCheckIntervalSeconds,
        public int $articleFetchIntervalSeconds,
        public int $requestTimeoutSeconds,
        public array $config,
    ) {
        $this->assertNotBlank($this->assignmentId, 'assignmentId');
        $this->assertNotBlank($this->sourceId, 'sourceId');
        $this->assertNotBlank($this->sourceDisplayName, 'sourceDisplayName');
        $this->assertNotBlank($this->listingMode, 'listingMode');
        $this->assertNotBlank($this->listingUrl, 'listingUrl');
        $this->assertNotBlank($this->articleMode, 'articleMode');
        $this->assertPositive($this->listingCheckIntervalSeconds, 'listingCheckIntervalSeconds');
        $this->assertPositive($this->articleFetchIntervalSeconds, 'articleFetchIntervalSeconds');
        $this->assertPositive($this->requestTimeoutSeconds, 'requestTimeoutSeconds');
    }

    private function assertNotBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException($field.' must not be blank.');
        }
    }

    private function assertPositive(int $value, string $field): void
    {
        if ($value <= 0) {
            throw new \InvalidArgumentException($field.' must be greater than zero.');
        }
    }
}

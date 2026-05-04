<?php

declare(strict_types=1);

namespace App\State;

final readonly class PendingArticle
{
    public function __construct(
        public string $assignmentId,
        public string $externalUrl,
        public string $sourceCode,
    ) {
        $this->assertNotBlank($this->assignmentId, 'assignmentId');
        $this->assertNotBlank($this->externalUrl, 'externalUrl');
        $this->assertNotBlank($this->sourceCode, 'sourceCode');
    }

    private function assertNotBlank(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new \InvalidArgumentException($field.' must not be blank.');
        }
    }
}

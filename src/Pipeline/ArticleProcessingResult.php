<?php

declare(strict_types=1);

namespace App\Pipeline;

final readonly class ArticleProcessingResult
{
    public function __construct(
        public ArticleProcessingStatus $status,
        public string $externalUrl,
        public ?string $title = null,
        public ?int $contentLength = null,
        public ?string $error = null,
    ) {
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    public function toTableRow(): array
    {
        return [
            $this->status->value,
            $this->externalUrl,
            $this->title ?? '',
            $this->contentLength === null ? '' : (string) $this->contentLength,
            $this->error ?? '',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Article;

final readonly class ParsedArticle
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $externalUrl,
        public string $sourceCode,
        public string $categoryCode,
        public string $title,
        public string $content,
        public ?\DateTimeImmutable $publishedAt,
        public ?string $author,
        public array $metadata = [],
    ) {
        if ($this->externalUrl === '') {
            throw new \InvalidArgumentException('Parsed article external URL must not be empty.');
        }

        if ($this->sourceCode === '') {
            throw new \InvalidArgumentException('Parsed article source code must not be empty.');
        }

        if ($this->categoryCode === '') {
            throw new \InvalidArgumentException('Parsed article category code must not be empty.');
        }

        if ($this->title === '') {
            throw new \InvalidArgumentException('Parsed article title must not be empty.');
        }

        if ($this->content === '') {
            throw new \InvalidArgumentException('Parsed article content must not be empty.');
        }
    }

    public function contentLength(): int
    {
        return strlen($this->content);
    }
}

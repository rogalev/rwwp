<?php

declare(strict_types=1);

namespace App\Output;

use App\Article\ParsedArticle;

final readonly class NdjsonParsedArticleSink implements ParsedArticleSinkInterface
{
    public function __construct(
        private string $path,
    ) {
    }

    public function write(ParsedArticle $article): void
    {
        $this->ensureDirectoryExists();

        $payload = [
            'externalUrl' => $article->externalUrl,
            'sourceCode' => $article->sourceCode,
            'categoryCode' => $article->categoryCode,
            'title' => $article->title,
            'content' => $article->content,
            'publishedAt' => $article->publishedAt?->format(\DateTimeInterface::ATOM),
            'author' => $article->author,
            'metadata' => $article->metadata,
            'parsedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        file_put_contents($this->path, $json."\n", FILE_APPEND | LOCK_EX);
    }

    private function ensureDirectoryExists(): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create parsed article output directory "%s".', $directory));
        }
    }
}

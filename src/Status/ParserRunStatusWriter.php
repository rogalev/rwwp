<?php

declare(strict_types=1);

namespace App\Status;

final readonly class ParserRunStatusWriter
{
    public function __construct(
        private string $path,
    ) {
    }

    /**
     * @param array<string, mixed> $status
     */
    public function write(array $status): void
    {
        $directory = dirname($this->path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create parser status directory "%s".', $directory));
        }

        $payload = [
            'checkedAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ...$status,
        ];
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        file_put_contents($this->path, $json."\n", LOCK_EX);
    }
}

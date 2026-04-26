<?php

declare(strict_types=1);

namespace App\Status;

final readonly class ParserRunStatusReader
{
    public function __construct(
        private string $path,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (!is_file($this->path)) {
            throw new \RuntimeException(sprintf('Parser status file "%s" does not exist.', $this->path));
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read parser status file "%s".', $this->path));
        }

        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($payload) || array_is_list($payload)) {
            throw new \RuntimeException(sprintf('Parser status file "%s" must contain a JSON object.', $this->path));
        }

        return $payload;
    }
}

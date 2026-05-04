<?php

declare(strict_types=1);

namespace App\Diagnostics;

final readonly class NdjsonDiagnosticLogger implements DiagnosticLoggerInterface
{
    public function __construct(
        private bool $enabled,
        private string $path,
    ) {
    }

    public function log(string $event, array $context = []): void
    {
        if (!$this->enabled) {
            return;
        }

        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create diagnostic log directory "%s".', $directory));
        }

        $payload = [
            'time' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'event' => $event,
            ...$this->sanitize($context),
        ];

        file_put_contents(
            $this->path,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function sanitize(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (\in_array(strtolower($key), ['authorization', 'apikey', 'api_key', 'rawhtml'], true)) {
                continue;
            }

            $safe[$key] = $this->safeValue($value);
        }

        return $safe;
    }

    private function safeValue(mixed $value): mixed
    {
        if (\is_array($value)) {
            $safe = [];
            foreach ($value as $key => $item) {
                $key = \is_string($key) ? $key : (string) $key;
                if (\in_array(strtolower($key), ['authorization', 'apikey', 'api_key', 'rawhtml'], true)) {
                    continue;
                }

                $safe[$key] = $this->safeValue($item);
            }

            return $safe;
        }

        if (\is_string($value) && mb_strlen($value) > 1000) {
            return mb_substr($value, 0, 1000).'...';
        }

        return $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Status;

use PDO;

final readonly class RuntimeMetricsCollector
{
    public function __construct(
        private string $stateDsn,
        private string $diagnosticLogPath,
        private string $hostLabel = '',
    ) {
    }

    /**
     * @return array<string, int|float|string|null>
     */
    public function collect(): array
    {
        $statePath = $this->sqlitePath($this->stateDsn);

        return [
            'hostLabel' => $this->hostLabel,
            'hostname' => gethostname() ?: '',
            'uptimeSeconds' => $this->uptimeSeconds(),
            'diskTotalBytes' => $this->diskTotalBytes($statePath),
            'diskUsedBytes' => $this->diskUsedBytes($statePath),
            'diskFreeBytes' => $this->diskFreeBytes($statePath),
            'memoryTotalBytes' => $this->memoryMetricBytes('MemTotal'),
            'memoryUsedBytes' => $this->memoryUsedBytes(),
            'memoryAvailableBytes' => $this->memoryMetricBytes('MemAvailable'),
            'loadAverage1m' => $this->loadAverage(0),
            'loadAverage5m' => $this->loadAverage(1),
            'loadAverage15m' => $this->loadAverage(2),
            'sqliteStateSizeBytes' => $this->fileSize($statePath),
            'diagnosticLogSizeBytes' => $this->fileSize($this->diagnosticLogPath),
            'pendingQueueSize' => $this->countRows('pending_articles', "status = 'pending'"),
            'failedQueueSize' => $this->countRows('pending_articles', "status = 'failed'"),
            'seenArticlesCount' => $this->countRows('seen_articles'),
            'oldestPendingAgeSeconds' => $this->oldestPendingAgeSeconds(),
        ];
    }

    private function uptimeSeconds(): ?int
    {
        $content = $this->readFile('/proc/uptime');
        if ($content === null) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($content));
        if ($parts === false || !isset($parts[0]) || !is_numeric($parts[0])) {
            return null;
        }

        return (int) floor((float) $parts[0]);
    }

    private function diskTotalBytes(?string $path): ?int
    {
        $directory = $this->existingDirectory($path);
        if ($directory === null) {
            return null;
        }

        $total = disk_total_space($directory);

        return $total === false ? null : (int) $total;
    }

    private function diskFreeBytes(?string $path): ?int
    {
        $directory = $this->existingDirectory($path);
        if ($directory === null) {
            return null;
        }

        $free = disk_free_space($directory);

        return $free === false ? null : (int) $free;
    }

    private function diskUsedBytes(?string $path): ?int
    {
        $total = $this->diskTotalBytes($path);
        $free = $this->diskFreeBytes($path);
        if ($total === null || $free === null) {
            return null;
        }

        return max(0, $total - $free);
    }

    private function memoryUsedBytes(): ?int
    {
        $total = $this->memoryMetricBytes('MemTotal');
        $available = $this->memoryMetricBytes('MemAvailable');
        if ($total === null || $available === null) {
            return null;
        }

        return max(0, $total - $available);
    }

    private function memoryMetricBytes(string $metric): ?int
    {
        $content = $this->readFile('/proc/meminfo');
        if ($content === null) {
            return null;
        }

        if (preg_match('/^'.preg_quote($metric, '/').':\s+(\d+)\s+kB$/m', $content, $matches) !== 1) {
            return null;
        }

        return ((int) $matches[1]) * 1024;
    }

    private function loadAverage(int $index): ?float
    {
        $load = sys_getloadavg();
        if ($load === false || !isset($load[$index])) {
            return null;
        }

        return (float) $load[$index];
    }

    private function fileSize(?string $path): ?int
    {
        if ($path === null || !is_file($path)) {
            return null;
        }

        $size = filesize($path);

        return $size === false ? null : $size;
    }

    private function countRows(string $table, string $where = ''): ?int
    {
        return $this->withConnection(function (PDO $connection) use ($table, $where): ?int {
            if (!$this->tableExists($connection, $table)) {
                return null;
            }

            $sql = 'SELECT COUNT(*) FROM '.$table;
            if ($where !== '') {
                $sql .= ' WHERE '.$where;
            }

            $value = $connection->query($sql)?->fetchColumn();

            return $value === false ? null : (int) $value;
        });
    }

    private function oldestPendingAgeSeconds(): ?int
    {
        return $this->withConnection(function (PDO $connection): ?int {
            if (!$this->tableExists($connection, 'pending_articles')) {
                return null;
            }

            $value = $connection
                ->query("SELECT MIN(first_seen_at) FROM pending_articles WHERE status = 'pending'")
                ?->fetchColumn();

            if (!is_string($value) || trim($value) === '') {
                return null;
            }

            try {
                $firstSeenAt = new \DateTimeImmutable($value);
            } catch (\Exception) {
                return null;
            }

            return max(0, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp() - $firstSeenAt->getTimestamp());
        });
    }

    /**
     * @template T
     *
     * @param callable(PDO): T $callback
     *
     * @return T|null
     */
    private function withConnection(callable $callback): mixed
    {
        if ($this->sqlitePath($this->stateDsn) === null) {
            return null;
        }

        try {
            $connection = new PDO($this->stateDsn);
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $callback($connection);
        } catch (\Throwable) {
            return null;
        }
    }

    private function tableExists(PDO $connection, string $table): bool
    {
        $statement = $connection->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table");
        $statement->execute(['table' => $table]);

        return $statement->fetchColumn() !== false;
    }

    private function sqlitePath(string $dsn): ?string
    {
        if (!str_starts_with($dsn, 'sqlite:')) {
            return null;
        }

        $path = substr($dsn, strlen('sqlite:'));

        return $path === ':memory:' ? null : $path;
    }

    private function existingDirectory(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $directory = is_dir($path) ? $path : dirname($path);

        while (!is_dir($directory)) {
            $parent = dirname($directory);
            if ($parent === $directory) {
                return null;
            }

            $directory = $parent;
        }

        return $directory;
    }

    private function readFile(string $path): ?string
    {
        if (!is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);

        return $content === false ? null : $content;
    }
}

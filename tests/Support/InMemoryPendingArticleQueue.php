<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\State\PendingArticle;
use App\State\PendingArticleQueueInterface;

final class InMemoryPendingArticleQueue implements PendingArticleQueueInterface
{
    /**
     * @var array<string, PendingArticle>
     */
    private array $pending = [];

    /**
     * @var array<string, bool>
     */
    private array $sent = [];

    /**
     * @var array<string, string>
     */
    private array $failed = [];

    public function enqueue(string $assignmentId, string $externalUrl, string $sourceKey): bool
    {
        $key = $this->key($assignmentId, $externalUrl);
        if (isset($this->pending[$key]) || isset($this->sent[$key]) || isset($this->failed[$key])) {
            return false;
        }

        $this->pending[$key] = new PendingArticle($assignmentId, $externalUrl, $sourceKey);

        return true;
    }

    public function takePending(string $assignmentId, int $limit): array
    {
        $items = [];
        foreach ($this->pending as $item) {
            if ($item->assignmentId !== $assignmentId) {
                continue;
            }

            $items[] = $item;
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    public function markSent(string $assignmentId, string $externalUrl): void
    {
        $key = $this->key($assignmentId, $externalUrl);
        unset($this->pending[$key], $this->failed[$key]);
        $this->sent[$key] = true;
    }

    public function markFailed(string $assignmentId, string $externalUrl, string $error): void
    {
        $key = $this->key($assignmentId, $externalUrl);
        unset($this->pending[$key], $this->sent[$key]);
        $this->failed[$key] = $error;
    }

    private function key(string $assignmentId, string $externalUrl): string
    {
        return $assignmentId."\n".$externalUrl;
    }
}

<?php

declare(strict_types=1);

namespace App\State;

interface PendingArticleQueueInterface
{
    public function enqueue(string $assignmentId, string $externalUrl, string $sourceKey): bool;

    /**
     * @return list<PendingArticle>
     */
    public function takePending(string $assignmentId, int $limit): array;

    public function markSent(string $assignmentId, string $externalUrl): void;

    public function markFailed(string $assignmentId, string $externalUrl, string $error): void;
}

<?php

declare(strict_types=1);

namespace App\State;

interface SeenArticleStoreInterface
{
    public function has(string $externalUrl): bool;

    public function markSeen(string $externalUrl, string $sourceKey, string $scopeKey): void;

    public function markParsed(string $externalUrl): void;

    public function markFailed(string $externalUrl, string $error): void;
}

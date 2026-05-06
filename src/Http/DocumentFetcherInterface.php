<?php

declare(strict_types=1);

namespace App\Http;

interface DocumentFetcherInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function fetch(string $url, array $headers = [], ?float $timeout = null): FetchedDocument;
}

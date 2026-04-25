<?php

declare(strict_types=1);

namespace App\Http;

interface DocumentFetcherInterface
{
    public function fetch(string $url): FetchedDocument;
}

<?php

declare(strict_types=1);

namespace App\Listing;

interface HtmlListingSelectorStatsProviderInterface
{
    public function lastSelectorStats(): ?HtmlListingSelectorStats;
}

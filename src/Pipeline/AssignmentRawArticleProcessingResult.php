<?php

declare(strict_types=1);

namespace App\Pipeline;

final readonly class AssignmentRawArticleProcessingResult
{
    public function __construct(
        public int $found,
        public int $alreadySeen,
        public int $sent,
        public int $failed,
    ) {
    }
}

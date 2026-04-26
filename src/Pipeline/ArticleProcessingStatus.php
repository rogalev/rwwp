<?php

declare(strict_types=1);

namespace App\Pipeline;

enum ArticleProcessingStatus: string
{
    case AlreadySeen = 'ALREADY_SEEN';
    case Parsed = 'PARSED';
    case Failed = 'FAILED';
    case SkippedUnsupported = 'SKIPPED_UNSUPPORTED';
}

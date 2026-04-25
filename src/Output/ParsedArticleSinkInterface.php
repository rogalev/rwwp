<?php

declare(strict_types=1);

namespace App\Output;

use App\Article\ParsedArticle;

interface ParsedArticleSinkInterface
{
    public function write(ParsedArticle $article): void;
}

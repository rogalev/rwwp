<?php

declare(strict_types=1);

namespace App\Article;

use App\Listing\ExternalArticleRef;

interface ArticleParserInterface
{
    public function supports(ExternalArticleRef $ref): bool;

    public function parse(ExternalArticleRef $ref): ParsedArticle;
}

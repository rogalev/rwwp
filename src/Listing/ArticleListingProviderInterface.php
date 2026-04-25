<?php

declare(strict_types=1);

namespace App\Listing;

interface ArticleListingProviderInterface
{
    public function supports(ListingSource $source): bool;

    /**
     * @return iterable<ExternalArticleRef>
     */
    public function fetchArticleRefs(ListingSource $source): iterable;
}

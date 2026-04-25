<?php

declare(strict_types=1);

namespace App\Listing;

final readonly class ArticleListingProviderRegistry
{
    /**
     * @param iterable<ArticleListingProviderInterface> $providers
     */
    public function __construct(
        private iterable $providers,
    ) {
    }

    public function providerFor(ListingSource $source): ArticleListingProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($source)) {
                return $provider;
            }
        }

        throw new \RuntimeException(sprintf('No listing provider supports "%s".', $source->type->value));
    }
}

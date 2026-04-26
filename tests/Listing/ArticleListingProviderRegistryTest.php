<?php

declare(strict_types=1);

namespace App\Tests\Listing;

use App\Listing\ArticleListingProviderInterface;
use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use PHPUnit\Framework\TestCase;

final class ArticleListingProviderRegistryTest extends TestCase
{
    public function testProviderForReturnsSupportedProvider(): void
    {
        $unsupportedProvider = new FakeArticleListingProvider(false);
        $supportedProvider = new FakeArticleListingProvider(true);
        $source = $this->rssSource();

        $registry = new ArticleListingProviderRegistry([
            $unsupportedProvider,
            $supportedProvider,
        ]);

        self::assertSame($supportedProvider, $registry->providerFor($source));
    }

    public function testProviderForReturnsFirstSupportedProvider(): void
    {
        $firstProvider = new FakeArticleListingProvider(true);
        $secondProvider = new FakeArticleListingProvider(true);
        $source = $this->rssSource();

        $registry = new ArticleListingProviderRegistry([
            $firstProvider,
            $secondProvider,
        ]);

        self::assertSame($firstProvider, $registry->providerFor($source));
    }

    public function testProviderForFailsWhenNoProviderSupportsSource(): void
    {
        $registry = new ArticleListingProviderRegistry([
            new FakeArticleListingProvider(false),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No listing provider supports "rss_feed".');

        $registry->providerFor($this->rssSource());
    }

    private function rssSource(): ListingSource
    {
        return new ListingSource(
            ListingSourceType::RssFeed,
            'bbc',
            'world',
            'https://example.com/rss.xml',
        );
    }
}

final readonly class FakeArticleListingProvider implements ArticleListingProviderInterface
{
    public function __construct(
        private bool $supports,
    ) {
    }

    public function supports(ListingSource $source): bool
    {
        return $this->supports;
    }

    public function fetchArticleRefs(ListingSource $source): iterable
    {
        yield from [];
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Listing;

use App\Http\DocumentFetcherInterface;
use App\Http\FetchedDocument;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\Listing\RssArticleListingProvider;
use App\Url\TrackingQueryUrlNormalizer;
use PHPUnit\Framework\TestCase;

final class RssArticleListingProviderTest extends TestCase
{
    public function testFetchArticleRefsReadsRssFixtureAndNormalizesUniqueLinks(): void
    {
        $source = new ListingSource(
            ListingSourceType::RssFeed,
            'bbc',
            'world',
            'https://feeds.example.test/world/rss.xml',
        );
        $provider = new RssArticleListingProvider(
            new FakeDocumentFetcher($this->rssFixture()),
            new TrackingQueryUrlNormalizer(),
        );

        $refs = iterator_to_array($provider->fetchArticleRefs($source), false);

        self::assertCount(2, $refs);

        self::assertSame('https://www.bbc.com/news/articles/c123?id=42', $refs[0]->externalUrl);
        self::assertSame('https://www.bbc.com/news/articles/c456?page=2', $refs[1]->externalUrl);

        foreach ($refs as $ref) {
            self::assertSame('bbc', $ref->sourceCode);
            self::assertSame('world', $ref->categoryCode);
            self::assertSame(ListingSourceType::RssFeed, $ref->listingSourceType);
        }
    }

    public function testFetchArticleRefsRejectsUnsupportedSourceType(): void
    {
        $provider = new RssArticleListingProvider(
            new FakeDocumentFetcher($this->rssFixture()),
            new TrackingQueryUrlNormalizer(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported listing source type "html_section".');

        iterator_to_array($provider->fetchArticleRefs(new ListingSource(
            ListingSourceType::HtmlSection,
            'bbc',
            'world',
            'https://example.com/world',
        )));
    }

    private function rssFixture(): string
    {
        $content = file_get_contents(__DIR__.'/../Fixtures/rss/bbc-world.xml');
        self::assertIsString($content);

        return $content;
    }
}

final readonly class FakeDocumentFetcher implements DocumentFetcherInterface
{
    public function __construct(
        private string $content,
    ) {
    }

    public function fetch(string $url): FetchedDocument
    {
        return new FetchedDocument(
            url: $url,
            statusCode: 200,
            content: $this->content,
            contentType: 'application/rss+xml',
            userAgent: 'PHPUnit',
            fetchedAt: new \DateTimeImmutable('2026-04-26T10:15:00+00:00'),
        );
    }
}

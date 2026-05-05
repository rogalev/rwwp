<?php

declare(strict_types=1);

namespace App\Tests\Listing;

use App\Http\DocumentFetcherInterface;
use App\Http\FetchedDocument;
use App\Listing\HtmlArticleListingProvider;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\Url\TrackingQueryUrlNormalizer;
use PHPUnit\Framework\TestCase;

final class HtmlArticleListingProviderTest extends TestCase
{
    public function testFetchArticleRefsReadsHtmlFixtureAndNormalizesUniqueLinks(): void
    {
        $source = new ListingSource(
            ListingSourceType::HtmlSection,
            'example',
            'world',
            'https://www.example.com/news/world/',
            [
                'listing' => [
                    'linkSelector' => '.news-card__link',
                ],
            ],
        );
        $provider = new HtmlArticleListingProvider(
            new FakeHtmlDocumentFetcher($this->htmlFixture()),
            new TrackingQueryUrlNormalizer(),
        );

        $refs = iterator_to_array($provider->fetchArticleRefs($source), false);

        self::assertCount(2, $refs);

        self::assertSame('https://www.example.com/news/articles/c123?id=42', $refs[0]->externalUrl);
        self::assertSame('https://www.example.com/news/world/articles/c456?page=2', $refs[1]->externalUrl);

        foreach ($refs as $ref) {
            self::assertSame('example', $ref->sourceCode);
            self::assertSame('world', $ref->categoryCode);
            self::assertSame(ListingSourceType::HtmlSection, $ref->listingSourceType);
        }
    }

    public function testFetchArticleRefsFailsWhenSelectorIsNotConfigured(): void
    {
        $provider = new HtmlArticleListingProvider(
            new FakeHtmlDocumentFetcher($this->htmlFixture()),
            new TrackingQueryUrlNormalizer(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTML listing selector is not configured in assignment config field "listing.linkSelector".');

        iterator_to_array($provider->fetchArticleRefs(new ListingSource(
            ListingSourceType::HtmlSection,
            'example',
            'world',
            'https://www.example.com/news/world/',
        )));
    }

    public function testFetchArticleRefsRejectsUnsupportedSourceType(): void
    {
        $provider = new HtmlArticleListingProvider(
            new FakeHtmlDocumentFetcher($this->htmlFixture()),
            new TrackingQueryUrlNormalizer(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported listing source type "rss_feed".');

        iterator_to_array($provider->fetchArticleRefs(new ListingSource(
            ListingSourceType::RssFeed,
            'example',
            'world',
            'https://feeds.example.test/rss.xml',
        )));
    }

    private function htmlFixture(): string
    {
        $content = file_get_contents(__DIR__.'/../Fixtures/html/news-section.html');
        self::assertIsString($content);

        return $content;
    }
}

final readonly class FakeHtmlDocumentFetcher implements DocumentFetcherInterface
{
    public function __construct(
        private string $content,
    ) {
    }

    public function fetch(string $url, array $headers = []): FetchedDocument
    {
        return new FetchedDocument(
            url: $url,
            statusCode: 200,
            content: $this->content,
            contentType: 'text/html',
            userAgent: 'PHPUnit',
            fetchedAt: new \DateTimeImmutable('2026-04-26T10:15:00+00:00'),
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Article;

use App\Article\ArticleParserInterface;
use App\Article\ArticleParserRegistry;
use App\Article\ParsedArticle;
use App\Listing\ExternalArticleRef;
use App\Listing\ListingSourceType;
use PHPUnit\Framework\TestCase;

final class ArticleParserRegistryTest extends TestCase
{
    public function testParserForReturnsSupportedParser(): void
    {
        $unsupportedParser = new FakeArticleParser(false);
        $supportedParser = new FakeArticleParser(true);
        $ref = $this->articleRef();

        $registry = new ArticleParserRegistry([
            $unsupportedParser,
            $supportedParser,
        ]);

        self::assertSame($supportedParser, $registry->parserFor($ref));
    }

    public function testParserForReturnsFirstSupportedParser(): void
    {
        $firstParser = new FakeArticleParser(true);
        $secondParser = new FakeArticleParser(true);
        $ref = $this->articleRef();

        $registry = new ArticleParserRegistry([
            $firstParser,
            $secondParser,
        ]);

        self::assertSame($firstParser, $registry->parserFor($ref));
    }

    public function testParserForFailsWhenNoParserSupportsRef(): void
    {
        $registry = new ArticleParserRegistry([
            new FakeArticleParser(false),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No article parser supports "https://example.com/news/42".');

        $registry->parserFor($this->articleRef());
    }

    private function articleRef(): ExternalArticleRef
    {
        return new ExternalArticleRef(
            'https://example.com/news/42',
            'bbc',
            'world',
            ListingSourceType::RssFeed,
        );
    }
}

final readonly class FakeArticleParser implements ArticleParserInterface
{
    public function __construct(
        private bool $supports,
    ) {
    }

    public function supports(ExternalArticleRef $ref): bool
    {
        return $this->supports;
    }

    public function parse(ExternalArticleRef $ref): ParsedArticle
    {
        return new ParsedArticle(
            $ref->externalUrl,
            $ref->sourceCode,
            $ref->categoryCode,
            'Example title',
            'Example content.',
            null,
            null,
        );
    }
}

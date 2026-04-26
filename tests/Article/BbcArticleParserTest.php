<?php

declare(strict_types=1);

namespace App\Tests\Article;

use App\Article\ArticleParseException;
use App\Article\BbcArticleParser;
use App\Http\DocumentFetcherInterface;
use App\Http\FetchedDocument;
use App\Listing\ExternalArticleRef;
use App\Listing\ListingSourceType;
use PHPUnit\Framework\TestCase;

final class BbcArticleParserTest extends TestCase
{
    public function testParseReadsArticleFixture(): void
    {
        $ref = $this->articleRef();
        $parser = new BbcArticleParser(new FakeArticleDocumentFetcher($this->articleFixture()));

        $article = $parser->parse($ref);

        self::assertSame('https://www.bbc.com/news/articles/c123', $article->externalUrl);
        self::assertSame('bbc', $article->sourceCode);
        self::assertSame('world', $article->categoryCode);
        self::assertSame('Example BBC headline', $article->title);
        self::assertSame("First article paragraph.\n\nSecond article paragraph.", $article->content);
        self::assertSame('2026-04-26T10:15:00+00:00', $article->publishedAt?->format(\DateTimeInterface::ATOM));
        self::assertSame('Jane Doe', $article->author);
        self::assertSame(BbcArticleParser::class, $article->metadata['parser']);
        self::assertSame('text/html', $article->metadata['contentType']);
        self::assertSame('PHPUnit', $article->metadata['userAgent']);
    }

    public function testParseRejectsUnsupportedRef(): void
    {
        $parser = new BbcArticleParser(new FakeArticleDocumentFetcher($this->articleFixture()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('BBC article parser does not support "https://example.com/news/articles/c123".');

        $parser->parse(new ExternalArticleRef(
            'https://example.com/news/articles/c123',
            'example',
            'world',
            ListingSourceType::HtmlSection,
        ));
    }

    public function testParseFailsWhenTitleIsMissing(): void
    {
        $parser = new BbcArticleParser(new FakeArticleDocumentFetcher('<main><p>Only content.</p></main>'));

        $this->expectException(ArticleParseException::class);
        $this->expectExceptionMessage('Unable to parse required field "title" from article "https://www.bbc.com/news/articles/c123".');

        $parser->parse($this->articleRef());
    }

    public function testParseFailsWhenContentIsMissing(): void
    {
        $parser = new BbcArticleParser(new FakeArticleDocumentFetcher('<main><h1>Only title.</h1></main>'));

        $this->expectException(ArticleParseException::class);
        $this->expectExceptionMessage('Unable to parse required field "content" from article "https://www.bbc.com/news/articles/c123".');

        $parser->parse($this->articleRef());
    }

    private function articleRef(): ExternalArticleRef
    {
        return new ExternalArticleRef(
            'https://www.bbc.com/news/articles/c123',
            'bbc',
            'world',
            ListingSourceType::RssFeed,
        );
    }

    private function articleFixture(): string
    {
        $content = file_get_contents(__DIR__.'/../Fixtures/html/bbc-article.html');
        self::assertIsString($content);

        return $content;
    }
}

final readonly class FakeArticleDocumentFetcher implements DocumentFetcherInterface
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
            contentType: 'text/html',
            userAgent: 'PHPUnit',
            fetchedAt: new \DateTimeImmutable('2026-04-26T10:15:00+00:00'),
        );
    }
}

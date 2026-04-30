<?php

declare(strict_types=1);

namespace App\Tests\Pipeline;

use App\Http\DocumentFetcherInterface;
use App\Http\FetchedDocument;
use App\Listing\ArticleListingProviderInterface;
use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ExternalArticleRef;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\MainApi\MainApiRawArticleSenderInterface;
use App\MainApi\ParserAssignment;
use App\MainApi\SendRawArticleResult;
use App\Pipeline\AssignmentRawArticleProcessor;
use App\State\SeenArticleStoreInterface;
use PHPUnit\Framework\TestCase;

final class AssignmentRawArticleProcessorTest extends TestCase
{
    public function testSendsRawArticleForNewRef(): void
    {
        $state = new RawProcessorSeenStore(alreadySeen: false);
        $fetcher = new RawProcessorDocumentFetcher();
        $sender = new RawProcessorArticleSender();
        $processor = $this->processor($state, $fetcher, $sender, [
            $this->articleRef('https://example.com/news/1'),
        ]);

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(1, $result->found);
        self::assertSame(0, $result->alreadySeen);
        self::assertSame(1, $result->sent);
        self::assertSame(0, $result->failed);
        self::assertSame(['https://example.com/news/1'], $fetcher->fetchedUrls);
        self::assertCount(1, $sender->sentArticles);
        self::assertSame('0196a222-2222-7222-8222-222222222222', $sender->sentArticles[0]['assignmentId']);
        self::assertSame('https://example.com/news/1', $sender->sentArticles[0]['externalUrl']);
        self::assertSame('<html>Article</html>', $sender->sentArticles[0]['rawHtml']);
        self::assertSame(['https://example.com/news/1'], $state->parsedMarks);
        self::assertSame([], $state->failedMarks);
    }

    public function testSkipsAlreadySeenRef(): void
    {
        $state = new RawProcessorSeenStore(alreadySeen: true);
        $fetcher = new RawProcessorDocumentFetcher();
        $sender = new RawProcessorArticleSender();
        $processor = $this->processor($state, $fetcher, $sender, [
            $this->articleRef('https://example.com/news/1'),
        ]);

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(1, $result->found);
        self::assertSame(1, $result->alreadySeen);
        self::assertSame(0, $result->sent);
        self::assertSame(0, $result->failed);
        self::assertSame([], $fetcher->fetchedUrls);
        self::assertSame([], $sender->sentArticles);
        self::assertSame([], $state->seenMarks);
    }

    public function testMarksFailedWhenSendingFails(): void
    {
        $state = new RawProcessorSeenStore(alreadySeen: false);
        $fetcher = new RawProcessorDocumentFetcher();
        $sender = new RawProcessorArticleSender(sendException: new \RuntimeException('Main API failed.'));
        $processor = $this->processor($state, $fetcher, $sender, [
            $this->articleRef('https://example.com/news/1'),
        ]);

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(1, $result->found);
        self::assertSame(0, $result->alreadySeen);
        self::assertSame(0, $result->sent);
        self::assertSame(1, $result->failed);
        self::assertSame([], $state->parsedMarks);
        self::assertSame([
            [
                'externalUrl' => 'https://example.com/news/1',
                'error' => 'Main API failed.',
            ],
        ], $state->failedMarks);
    }

    /**
     * @param list<ExternalArticleRef> $articleRefs
     */
    private function processor(
        RawProcessorSeenStore $state,
        RawProcessorDocumentFetcher $fetcher,
        RawProcessorArticleSender $sender,
        array $articleRefs,
    ): AssignmentRawArticleProcessor {
        return new AssignmentRawArticleProcessor(
            new ArticleListingProviderRegistry([new RawProcessorListingProvider($articleRefs)]),
            $fetcher,
            $sender,
            $state,
        );
    }

    private function assignment(): ParserAssignment
    {
        return new ParserAssignment(
            assignmentId: '0196a222-2222-7222-8222-222222222222',
            sourceId: '0196a111-1111-7111-8111-111111111111',
            sourceDisplayName: 'BBC',
            listingMode: 'rss',
            listingUrl: 'https://feeds.bbci.co.uk/news/world/rss.xml',
            articleMode: 'html',
            listingCheckIntervalSeconds: 300,
            articleFetchIntervalSeconds: 10,
            requestTimeoutSeconds: 15,
            config: [],
        );
    }

    private function articleRef(string $externalUrl): ExternalArticleRef
    {
        return new ExternalArticleRef(
            externalUrl: $externalUrl,
            sourceCode: '0196a111-1111-7111-8111-111111111111',
            categoryCode: '0196a222-2222-7222-8222-222222222222',
            listingSourceType: ListingSourceType::RssFeed,
        );
    }
}

final readonly class RawProcessorListingProvider implements ArticleListingProviderInterface
{
    /**
     * @param list<ExternalArticleRef> $articleRefs
     */
    public function __construct(
        private array $articleRefs,
    ) {
    }

    public function supports(ListingSource $source): bool
    {
        return true;
    }

    public function fetchArticleRefs(ListingSource $source): iterable
    {
        return $this->articleRefs;
    }
}

final class RawProcessorSeenStore implements SeenArticleStoreInterface
{
    /**
     * @var list<array{externalUrl: string, sourceCode: string, categoryCode: string}>
     */
    public array $seenMarks = [];

    /**
     * @var list<string>
     */
    public array $parsedMarks = [];

    /**
     * @var list<array{externalUrl: string, error: string}>
     */
    public array $failedMarks = [];

    public function __construct(
        private readonly bool $alreadySeen,
    ) {
    }

    public function has(string $externalUrl): bool
    {
        return $this->alreadySeen;
    }

    public function markSeen(string $externalUrl, string $sourceCode, string $categoryCode): void
    {
        $this->seenMarks[] = [
            'externalUrl' => $externalUrl,
            'sourceCode' => $sourceCode,
            'categoryCode' => $categoryCode,
        ];
    }

    public function markParsed(string $externalUrl): void
    {
        $this->parsedMarks[] = $externalUrl;
    }

    public function markFailed(string $externalUrl, string $error): void
    {
        $this->failedMarks[] = [
            'externalUrl' => $externalUrl,
            'error' => $error,
        ];
    }
}

final class RawProcessorDocumentFetcher implements DocumentFetcherInterface
{
    /**
     * @var list<string>
     */
    public array $fetchedUrls = [];

    public function fetch(string $url): FetchedDocument
    {
        $this->fetchedUrls[] = $url;

        return new FetchedDocument(
            url: $url,
            statusCode: 200,
            content: '<html>Article</html>',
            contentType: 'text/html',
            userAgent: 'PHPUnit User-Agent',
            fetchedAt: new \DateTimeImmutable('2026-04-30T10:00:00+00:00'),
        );
    }
}

final class RawProcessorArticleSender implements MainApiRawArticleSenderInterface
{
    /**
     * @var list<array{assignmentId: string, externalUrl: string, rawHtml: string, httpStatusCode: int, fetchedAt: string}>
     */
    public array $sentArticles = [];

    public function __construct(
        private readonly ?\Throwable $sendException = null,
    ) {
    }

    public function send(
        string $assignmentId,
        string $externalUrl,
        string $rawHtml,
        int $httpStatusCode,
        \DateTimeImmutable $fetchedAt,
    ): SendRawArticleResult {
        if ($this->sendException !== null) {
            throw $this->sendException;
        }

        $this->sentArticles[] = [
            'assignmentId' => $assignmentId,
            'externalUrl' => $externalUrl,
            'rawHtml' => $rawHtml,
            'httpStatusCode' => $httpStatusCode,
            'fetchedAt' => $fetchedAt->format(\DateTimeInterface::ATOM),
        ];

        return new SendRawArticleResult(
            id: '0196a333-3333-7333-8333-333333333333',
            created: true,
            externalUrl: $externalUrl,
            contentHash: 'content-hash',
        );
    }
}

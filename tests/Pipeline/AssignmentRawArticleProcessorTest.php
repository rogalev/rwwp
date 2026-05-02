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
use App\MainApi\MainApiParserFailureSenderInterface;
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
        self::assertSame([200 => 1], $result->httpStatusCodes);
        self::assertSame(0, $result->transportErrors);
        self::assertSame(['https://example.com/news/1'], $fetcher->fetchedUrls);
        self::assertCount(1, $sender->sentArticles);
        self::assertSame('0196a222-2222-7222-8222-222222222222', $sender->sentArticles[0]['assignmentId']);
        self::assertSame('https://example.com/news/1', $sender->sentArticles[0]['externalUrl']);
        self::assertSame('<html>Article</html>', $sender->sentArticles[0]['rawHtml']);
        self::assertSame(['https://example.com/news/1'], $state->parsedMarks);
        self::assertSame([], $state->failedMarks);
    }

    public function testUsesSafeHttpHeadersFromAssignmentConfig(): void
    {
        $state = new RawProcessorSeenStore(alreadySeen: false);
        $fetcher = new RawProcessorDocumentFetcher();
        $sender = new RawProcessorArticleSender();
        $processor = $this->processor($state, $fetcher, $sender, [
            $this->articleRef('https://example.com/news/1'),
        ]);

        $processor->process($this->assignment(config: [
            'httpHeaders' => [
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Referer' => 'https://example.com/',
                'Cookie' => 'secret=1',
                'Authorization' => 'Bearer secret',
                'X-Custom' => 'ignored',
                'Accept' => 'text/html',
            ],
        ]), limit: 10);

        self::assertSame([
            [
                'url' => 'https://example.com/news/1',
                'headers' => [
                    'Accept-Language' => 'ru-RU,ru;q=0.9',
                    'Referer' => 'https://example.com/',
                    'Accept' => 'text/html',
                ],
            ],
        ], $fetcher->fetches);
    }

    public function testIgnoresInvalidHttpHeadersConfig(): void
    {
        $state = new RawProcessorSeenStore(alreadySeen: false);
        $fetcher = new RawProcessorDocumentFetcher();
        $sender = new RawProcessorArticleSender();
        $processor = $this->processor($state, $fetcher, $sender, [
            $this->articleRef('https://example.com/news/1'),
        ]);

        $processor->process($this->assignment(config: [
            'httpHeaders' => 'not-an-object',
        ]), limit: 10);

        self::assertSame([
            [
                'url' => 'https://example.com/news/1',
                'headers' => [],
            ],
        ], $fetcher->fetches);
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
        self::assertSame([], $result->httpStatusCodes);
        self::assertSame(0, $result->transportErrors);
        self::assertSame([], $fetcher->fetchedUrls);
        self::assertSame([], $sender->sentArticles);
        self::assertSame([], $state->seenMarks);
    }

    public function testMarksFailedWhenSendingFails(): void
    {
        $state = new RawProcessorSeenStore(alreadySeen: false);
        $fetcher = new RawProcessorDocumentFetcher();
        $sender = new RawProcessorArticleSender(sendException: new \RuntimeException('Main API failed.'));
        $failureSender = new RawProcessorFailureSender();
        $processor = $this->processor($state, $fetcher, $sender, [
            $this->articleRef('https://example.com/news/1'),
        ], $failureSender);

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(1, $result->found);
        self::assertSame(0, $result->alreadySeen);
        self::assertSame(0, $result->sent);
        self::assertSame(1, $result->failed);
        self::assertSame([200 => 1], $result->httpStatusCodes);
        self::assertSame(1, $result->transportErrors);
        self::assertSame([], $state->parsedMarks);
        self::assertSame([
            [
                'externalUrl' => 'https://example.com/news/1',
                'error' => 'Main API failed.',
            ],
        ], $state->failedMarks);
        self::assertSame([
            [
                'assignmentId' => '0196a222-2222-7222-8222-222222222222',
                'stage' => 'raw_article_send',
                'message' => 'Main API failed.',
                'context' => [
                    'externalUrl' => 'https://example.com/news/1',
                    'exceptionClass' => \RuntimeException::class,
                ],
            ],
        ], $failureSender->failuresWithoutOccurredAt());
    }

    public function testCountsTransportErrorWhenFetchingFails(): void
    {
        $state = new RawProcessorSeenStore(alreadySeen: false);
        $fetcher = new RawProcessorDocumentFetcher(fetchException: new \RuntimeException('Network timeout.'));
        $sender = new RawProcessorArticleSender();
        $failureSender = new RawProcessorFailureSender();
        $processor = $this->processor($state, $fetcher, $sender, [
            $this->articleRef('https://example.com/news/1'),
        ], $failureSender);

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(1, $result->found);
        self::assertSame(0, $result->sent);
        self::assertSame(1, $result->failed);
        self::assertSame([], $result->httpStatusCodes);
        self::assertSame(1, $result->transportErrors);
        self::assertSame([], $sender->sentArticles);
        self::assertSame([
            [
                'externalUrl' => 'https://example.com/news/1',
                'error' => 'Network timeout.',
            ],
        ], $state->failedMarks);
        self::assertSame([
            [
                'assignmentId' => '0196a222-2222-7222-8222-222222222222',
                'stage' => 'article_fetch',
                'message' => 'Network timeout.',
                'context' => [
                    'externalUrl' => 'https://example.com/news/1',
                    'exceptionClass' => \RuntimeException::class,
                ],
            ],
        ], $failureSender->failuresWithoutOccurredAt());
    }

    public function testContinuesWhenFailureReportingFails(): void
    {
        $state = new RawProcessorSeenStore(alreadySeen: false);
        $fetcher = new RawProcessorDocumentFetcher(fetchException: new \RuntimeException('Network timeout.'));
        $sender = new RawProcessorArticleSender();
        $processor = $this->processor(
            $state,
            $fetcher,
            $sender,
            [$this->articleRef('https://example.com/news/1')],
            new RawProcessorFailureSender(sendException: new \RuntimeException('Main failure endpoint failed.')),
        );

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(1, $result->failed);
        self::assertSame([
            [
                'externalUrl' => 'https://example.com/news/1',
                'error' => 'Network timeout.',
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
        ?RawProcessorFailureSender $failureSender = null,
    ): AssignmentRawArticleProcessor {
        return new AssignmentRawArticleProcessor(
            new ArticleListingProviderRegistry([new RawProcessorListingProvider($articleRefs)]),
            $fetcher,
            $sender,
            $failureSender ?? new RawProcessorFailureSender(),
            $state,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function assignment(array $config = []): ParserAssignment
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
            config: $config,
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

    /**
     * @var list<array{url: string, headers: array<string, string>}>
     */
    public array $fetches = [];

    public function __construct(
        private readonly ?\Throwable $fetchException = null,
    ) {
    }

    public function fetch(string $url, array $headers = []): FetchedDocument
    {
        $this->fetchedUrls[] = $url;
        $this->fetches[] = [
            'url' => $url,
            'headers' => $headers,
        ];

        if ($this->fetchException !== null) {
            throw $this->fetchException;
        }

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

final class RawProcessorFailureSender implements MainApiParserFailureSenderInterface
{
    /**
     * @var list<array{assignmentId: string, stage: string, message: string, context: array<string, mixed>, occurredAt: string}>
     */
    public array $failures = [];

    public function __construct(
        private readonly ?\Throwable $sendException = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function send(
        string $assignmentId,
        string $stage,
        string $message,
        array $context,
        \DateTimeImmutable $occurredAt,
    ): void {
        if ($this->sendException !== null) {
            throw $this->sendException;
        }

        $this->failures[] = [
            'assignmentId' => $assignmentId,
            'stage' => $stage,
            'message' => $message,
            'context' => $context,
            'occurredAt' => $occurredAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return list<array{assignmentId: string, stage: string, message: string, context: array<string, mixed>}>
     */
    public function failuresWithoutOccurredAt(): array
    {
        return array_map(
            static fn (array $failure): array => [
                'assignmentId' => $failure['assignmentId'],
                'stage' => $failure['stage'],
                'message' => $failure['message'],
                'context' => $failure['context'],
            ],
            $this->failures,
        );
    }
}

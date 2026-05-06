<?php

declare(strict_types=1);

namespace App\Tests\Pipeline;

use App\Http\DocumentFetcherInterface;
use App\Http\FetchedDocument;
use App\MainApi\MainApiParserFailureSenderInterface;
use App\MainApi\MainApiRawArticleSenderInterface;
use App\MainApi\ParserAssignment;
use App\MainApi\SendRawArticleResult;
use App\Pipeline\AssignmentArticleFetchProcessor;
use App\State\PendingArticle;
use App\State\PendingArticleQueueInterface;
use App\State\SeenArticleStoreInterface;
use App\Tests\Support\NullDiagnosticLogger;
use PHPUnit\Framework\TestCase;

final class AssignmentArticleFetchProcessorTest extends TestCase
{
    public function testFetchesPendingArticleAndSendsRawHtml(): void
    {
        $queue = new ArticleFetchQueue([
            new PendingArticle('0196a222-2222-7222-8222-222222222222', 'https://example.com/news/1', 'source-1'),
        ]);
        $fetcher = new ArticleFetchDocumentFetcher();
        $sender = new ArticleFetchRawArticleSender();
        $seenStore = new ArticleFetchSeenStore();
        $processor = $this->processor($queue, $fetcher, $sender, $seenStore);

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(1, $result->sent);
        self::assertSame(0, $result->failed);
        self::assertSame([200 => 1], $result->httpStatusCodes);
        self::assertSame(0, $result->transportErrors);
        self::assertSame('raw_article_send', $result->stage);
        self::assertSame(['https://example.com/news/1'], $fetcher->fetchedUrls);
        self::assertSame(['https://example.com/news/1'], $queue->sentUrls);
        self::assertSame(['https://example.com/news/1'], $seenStore->parsedMarks);
        self::assertCount(1, $sender->sentArticles);
        self::assertSame('https://example.com/news/1', $sender->sentArticles[0]['externalUrl']);
    }

    public function testUsesSafeHttpHeadersFromAssignmentConfig(): void
    {
        $fetcher = new ArticleFetchDocumentFetcher();
        $processor = $this->processor(
            new ArticleFetchQueue([
                new PendingArticle('0196a222-2222-7222-8222-222222222222', 'https://example.com/news/1', 'source-1'),
            ]),
            $fetcher,
            new ArticleFetchRawArticleSender(),
            new ArticleFetchSeenStore(),
        );

        $processor->process($this->assignment(config: [
            'httpHeaders' => [
                'Accept-Language' => 'ru-RU,ru;q=0.9',
                'Referer' => 'https://example.com/',
                'Cookie' => 'secret=1',
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
                'timeout' => 15.0,
            ],
        ], $fetcher->fetches);
    }

    public function testMarksFailedWhenFetchFails(): void
    {
        $queue = new ArticleFetchQueue([
            new PendingArticle('0196a222-2222-7222-8222-222222222222', 'https://example.com/news/1', 'source-1'),
        ]);
        $seenStore = new ArticleFetchSeenStore();
        $failureSender = new ArticleFetchFailureSender();
        $processor = $this->processor(
            $queue,
            new ArticleFetchDocumentFetcher(fetchException: new \RuntimeException('Network timeout.')),
            new ArticleFetchRawArticleSender(),
            $seenStore,
            $failureSender,
        );

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(0, $result->sent);
        self::assertSame(1, $result->failed);
        self::assertSame(1, $result->transportErrors);
        self::assertSame('article_fetch', $result->stage);
        self::assertSame([
            [
                'externalUrl' => 'https://example.com/news/1',
                'error' => 'Network timeout.',
            ],
        ], $queue->failedMarks);
        self::assertSame([
            [
                'externalUrl' => 'https://example.com/news/1',
                'error' => 'Network timeout.',
            ],
        ], $seenStore->failedMarks);
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

    public function testMarksFailedWhenSendFails(): void
    {
        $queue = new ArticleFetchQueue([
            new PendingArticle('0196a222-2222-7222-8222-222222222222', 'https://example.com/news/1', 'source-1'),
        ]);
        $processor = $this->processor(
            $queue,
            new ArticleFetchDocumentFetcher(),
            new ArticleFetchRawArticleSender(sendException: new \RuntimeException('Main API failed.')),
            new ArticleFetchSeenStore(),
        );

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(0, $result->sent);
        self::assertSame(1, $result->failed);
        self::assertSame('raw_article_send', $result->stage);
        self::assertSame([
            [
                'externalUrl' => 'https://example.com/news/1',
                'error' => 'Main API failed.',
            ],
        ], $queue->failedMarks);
    }

    public function testContinuesWhenFailureReportingFails(): void
    {
        $queue = new ArticleFetchQueue([
            new PendingArticle('0196a222-2222-7222-8222-222222222222', 'https://example.com/news/1', 'source-1'),
        ]);
        $processor = $this->processor(
            $queue,
            new ArticleFetchDocumentFetcher(fetchException: new \RuntimeException('Network timeout.')),
            new ArticleFetchRawArticleSender(),
            new ArticleFetchSeenStore(),
            new ArticleFetchFailureSender(sendException: new \RuntimeException('Failure endpoint failed.')),
        );

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(1, $result->failed);
        self::assertSame(1, $result->transportErrors);
        self::assertSame('https://example.com/news/1', $queue->failedMarks[0]['externalUrl']);
    }

    private function processor(
        ArticleFetchQueue $queue,
        ArticleFetchDocumentFetcher $fetcher,
        ArticleFetchRawArticleSender $sender,
        ArticleFetchSeenStore $seenStore,
        ?ArticleFetchFailureSender $failureSender = null,
    ): AssignmentArticleFetchProcessor {
        return new AssignmentArticleFetchProcessor(
            $queue,
            $fetcher,
            $sender,
            $failureSender ?? new ArticleFetchFailureSender(),
            $seenStore,
            new NullDiagnosticLogger(),
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
}

final class ArticleFetchQueue implements PendingArticleQueueInterface
{
    /**
     * @var list<PendingArticle>
     */
    private array $pendingArticles;

    /**
     * @var list<string>
     */
    public array $sentUrls = [];

    /**
     * @var list<array{externalUrl: string, error: string}>
     */
    public array $failedMarks = [];

    /**
     * @param list<PendingArticle> $pendingArticles
     */
    public function __construct(array $pendingArticles)
    {
        $this->pendingArticles = $pendingArticles;
    }

    public function enqueue(string $assignmentId, string $externalUrl, string $sourceCode): bool
    {
        return true;
    }

    public function takePending(string $assignmentId, int $limit): array
    {
        return array_slice($this->pendingArticles, 0, $limit);
    }

    public function markSent(string $assignmentId, string $externalUrl): void
    {
        $this->sentUrls[] = $externalUrl;
    }

    public function markFailed(string $assignmentId, string $externalUrl, string $error): void
    {
        $this->failedMarks[] = [
            'externalUrl' => $externalUrl,
            'error' => $error,
        ];
    }
}

final class ArticleFetchDocumentFetcher implements DocumentFetcherInterface
{
    /**
     * @var list<string>
     */
    public array $fetchedUrls = [];

    /**
     * @var list<array{url: string, headers: array<string, string>, timeout: ?float}>
     */
    public array $fetches = [];

    public function __construct(
        private readonly ?\Throwable $fetchException = null,
    ) {
    }

    public function fetch(string $url, array $headers = [], ?float $timeout = null): FetchedDocument
    {
        $this->fetchedUrls[] = $url;
        $this->fetches[] = [
            'url' => $url,
            'headers' => $headers,
            'timeout' => $timeout,
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

final class ArticleFetchRawArticleSender implements MainApiRawArticleSenderInterface
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

final class ArticleFetchSeenStore implements SeenArticleStoreInterface
{
    /**
     * @var list<string>
     */
    public array $parsedMarks = [];

    /**
     * @var list<array{externalUrl: string, error: string}>
     */
    public array $failedMarks = [];

    public function has(string $externalUrl): bool
    {
        return false;
    }

    public function markSeen(string $externalUrl, string $sourceCode, string $categoryCode): void
    {
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

final class ArticleFetchFailureSender implements MainApiParserFailureSenderInterface
{
    /**
     * @var list<array{assignmentId: string, stage: string, message: string, context: array<string, mixed>, occurredAt: string}>
     */
    public array $failures = [];

    public function __construct(
        private readonly ?\Throwable $sendException = null,
    ) {
    }

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

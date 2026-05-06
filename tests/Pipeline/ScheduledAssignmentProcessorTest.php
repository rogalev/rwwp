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
use App\Pipeline\AssignmentArticleFetchProcessor;
use App\Pipeline\AssignmentListingEnqueueProcessor;
use App\Pipeline\ScheduledAssignmentProcessor;
use App\Schedule\AssignmentScheduleDecision;
use App\State\PendingArticle;
use App\State\PendingArticleQueueInterface;
use App\State\SeenArticleStoreInterface;
use App\Tests\Support\NullDiagnosticLogger;
use PHPUnit\Framework\TestCase;

final class ScheduledAssignmentProcessorTest extends TestCase
{
    public function testRunsListingAndArticleFetchWhenBothStagesAreDue(): void
    {
        $queue = new ScheduledQueue([
            new PendingArticle('assignment-1', 'https://example.com/news/pending', 'source-1'),
        ]);
        $rawSender = new ScheduledRawArticleSender();
        $processor = $this->processor(
            queue: $queue,
            listingRefs: [$this->articleRef('https://example.com/news/new')],
            rawSender: $rawSender,
        );

        $result = $processor->process(
            $this->assignment(),
            new AssignmentScheduleDecision(listingDue: true, articleFetchDue: true),
            limit: 10,
        );

        self::assertSame(1, $result->found);
        self::assertSame(1, $result->queued);
        self::assertSame(1, $result->sent);
        self::assertSame(0, $result->failed);
        self::assertSame([200 => 1], $result->httpStatusCodes);
        self::assertSame('raw_article_send', $result->stage);
        self::assertSame(['https://example.com/news/new'], $queue->enqueuedUrls);
        self::assertSame(['https://example.com/news/pending'], $queue->sentUrls);
        self::assertCount(1, $rawSender->sentArticles);
    }

    public function testRunsOnlyListingWhenOnlyListingIsDue(): void
    {
        $queue = new ScheduledQueue([
            new PendingArticle('assignment-1', 'https://example.com/news/pending', 'source-1'),
        ]);
        $rawSender = new ScheduledRawArticleSender();
        $processor = $this->processor(
            queue: $queue,
            listingRefs: [$this->articleRef('https://example.com/news/new')],
            rawSender: $rawSender,
        );

        $result = $processor->process(
            $this->assignment(),
            new AssignmentScheduleDecision(listingDue: true, articleFetchDue: false),
            limit: 10,
        );

        self::assertSame(1, $result->found);
        self::assertSame(1, $result->queued);
        self::assertSame(0, $result->sent);
        self::assertSame('listing', $result->stage);
        self::assertSame([], $queue->sentUrls);
        self::assertSame([], $rawSender->sentArticles);
    }

    public function testRunsOnlyArticleFetchWhenOnlyArticleFetchIsDue(): void
    {
        $queue = new ScheduledQueue([
            new PendingArticle('assignment-1', 'https://example.com/news/pending', 'source-1'),
        ]);
        $rawSender = new ScheduledRawArticleSender();
        $processor = $this->processor(
            queue: $queue,
            listingRefs: [$this->articleRef('https://example.com/news/new')],
            rawSender: $rawSender,
        );

        $result = $processor->process(
            $this->assignment(),
            new AssignmentScheduleDecision(listingDue: false, articleFetchDue: true),
            limit: 10,
        );

        self::assertSame(0, $result->found);
        self::assertSame(0, $result->queued);
        self::assertSame(1, $result->sent);
        self::assertSame('raw_article_send', $result->stage);
        self::assertSame([], $queue->enqueuedUrls);
        self::assertSame(['https://example.com/news/pending'], $queue->sentUrls);
    }

    public function testReturnsIdleWhenNothingIsDue(): void
    {
        $processor = $this->processor(
            queue: new ScheduledQueue([]),
            listingRefs: [$this->articleRef('https://example.com/news/new')],
            rawSender: new ScheduledRawArticleSender(),
        );

        $result = $processor->process(
            $this->assignment(),
            new AssignmentScheduleDecision(listingDue: false, articleFetchDue: false),
            limit: 10,
        );

        self::assertSame(0, $result->found);
        self::assertSame(0, $result->queued);
        self::assertSame(0, $result->sent);
        self::assertSame('idle', $result->stage);
    }

    /**
     * @param list<ExternalArticleRef> $listingRefs
     */
    private function processor(
        ScheduledQueue $queue,
        array $listingRefs,
        ScheduledRawArticleSender $rawSender,
    ): ScheduledAssignmentProcessor {
        $seenStore = new ScheduledSeenStore();
        $failureSender = new ScheduledFailureSender();

        return new ScheduledAssignmentProcessor(
            new AssignmentListingEnqueueProcessor(
                new ArticleListingProviderRegistry([new ScheduledListingProvider($listingRefs)]),
                $seenStore,
                $queue,
                $failureSender,
                new NullDiagnosticLogger(),
            ),
            new AssignmentArticleFetchProcessor(
                $queue,
                new ScheduledDocumentFetcher(),
                $rawSender,
                $failureSender,
                $seenStore,
                new NullDiagnosticLogger(),
            ),
        );
    }

    private function assignment(): ParserAssignment
    {
        return new ParserAssignment(
            assignmentId: 'assignment-1',
            sourceId: 'source-1',
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
            sourceCode: 'source-1',
            categoryCode: 'assignment-1',
            listingSourceType: ListingSourceType::RssFeed,
        );
    }
}

final readonly class ScheduledListingProvider implements ArticleListingProviderInterface
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

final class ScheduledQueue implements PendingArticleQueueInterface
{
    /**
     * @var list<PendingArticle>
     */
    private array $pendingArticles;

    /**
     * @var list<string>
     */
    public array $enqueuedUrls = [];

    /**
     * @var list<string>
     */
    public array $sentUrls = [];

    /**
     * @param list<PendingArticle> $pendingArticles
     */
    public function __construct(array $pendingArticles)
    {
        $this->pendingArticles = $pendingArticles;
    }

    public function enqueue(string $assignmentId, string $externalUrl, string $sourceCode): bool
    {
        $this->enqueuedUrls[] = $externalUrl;

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
    }
}

final readonly class ScheduledDocumentFetcher implements DocumentFetcherInterface
{
    public function fetch(string $url, array $headers = [], ?float $timeout = null): FetchedDocument
    {
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

final class ScheduledRawArticleSender implements MainApiRawArticleSenderInterface
{
    /**
     * @var list<array{assignmentId: string, externalUrl: string}>
     */
    public array $sentArticles = [];

    public function send(
        string $assignmentId,
        string $externalUrl,
        string $rawHtml,
        int $httpStatusCode,
        \DateTimeImmutable $fetchedAt,
    ): SendRawArticleResult {
        $this->sentArticles[] = [
            'assignmentId' => $assignmentId,
            'externalUrl' => $externalUrl,
        ];

        return new SendRawArticleResult('raw-article-1', true, $externalUrl, 'hash');
    }
}

final readonly class ScheduledSeenStore implements SeenArticleStoreInterface
{
    public function has(string $externalUrl): bool
    {
        return false;
    }

    public function markSeen(string $externalUrl, string $sourceCode, string $categoryCode): void
    {
    }

    public function markParsed(string $externalUrl): void
    {
    }

    public function markFailed(string $externalUrl, string $error): void
    {
    }
}

final readonly class ScheduledFailureSender implements MainApiParserFailureSenderInterface
{
    public function send(
        string $assignmentId,
        string $stage,
        string $message,
        array $context,
        \DateTimeImmutable $occurredAt,
    ): void {
    }
}

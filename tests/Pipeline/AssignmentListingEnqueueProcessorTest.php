<?php

declare(strict_types=1);

namespace App\Tests\Pipeline;

use App\Listing\ArticleListingProviderInterface;
use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ExternalArticleRef;
use App\Listing\HtmlListingSelectorStats;
use App\Listing\HtmlListingSelectorStatsProviderInterface;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\MainApi\MainApiParserFailureSenderInterface;
use App\MainApi\ParserAssignment;
use App\Pipeline\AssignmentListingEnqueueProcessor;
use App\State\PendingArticleQueueInterface;
use App\State\PendingArticle;
use App\State\SeenArticleStoreInterface;
use App\Diagnostics\DiagnosticLoggerInterface;
use App\Tests\Support\NullDiagnosticLogger;
use PHPUnit\Framework\TestCase;

final class AssignmentListingEnqueueProcessorTest extends TestCase
{
    public function testEnqueuesNewArticleRefs(): void
    {
        $seenStore = new ListingEnqueueSeenStore();
        $queue = new ListingEnqueueQueue();
        $processor = $this->processor(
            articleRefs: [
                $this->articleRef('https://example.com/news/1'),
                $this->articleRef('https://example.com/news/2'),
            ],
            seenStore: $seenStore,
            queue: $queue,
        );

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(2, $result->found);
        self::assertSame(0, $result->alreadySeen);
        self::assertSame(2, $result->queued);
        self::assertSame(0, $result->failed);
        self::assertSame([
            [
                'assignmentId' => '0196a222-2222-7222-8222-222222222222',
                'externalUrl' => 'https://example.com/news/1',
                'sourceKey' => '0196a111-1111-7111-8111-111111111111',
            ],
            [
                'assignmentId' => '0196a222-2222-7222-8222-222222222222',
                'externalUrl' => 'https://example.com/news/2',
                'sourceKey' => '0196a111-1111-7111-8111-111111111111',
            ],
        ], $queue->enqueued);
    }

    public function testSkipsAlreadySeenRefs(): void
    {
        $queue = new ListingEnqueueQueue();
        $processor = $this->processor(
            articleRefs: [$this->articleRef('https://example.com/news/1')],
            seenStore: new ListingEnqueueSeenStore(seenUrls: ['https://example.com/news/1']),
            queue: $queue,
        );

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(1, $result->found);
        self::assertSame(1, $result->alreadySeen);
        self::assertSame(0, $result->queued);
        self::assertSame([], $queue->enqueued);
    }

    public function testRespectsQueueLimit(): void
    {
        $queue = new ListingEnqueueQueue();
        $processor = $this->processor(
            articleRefs: [
                $this->articleRef('https://example.com/news/1'),
                $this->articleRef('https://example.com/news/2'),
            ],
            seenStore: new ListingEnqueueSeenStore(),
            queue: $queue,
        );

        $result = $processor->process($this->assignment(), limit: 1);

        self::assertSame(2, $result->found);
        self::assertSame(1, $result->queued);
        self::assertCount(1, $queue->enqueued);
    }

    public function testReportsListingFailure(): void
    {
        $failureSender = new ListingEnqueueFailureSender();
        $processor = $this->processor(
            articleRefs: [],
            seenStore: new ListingEnqueueSeenStore(),
            queue: new ListingEnqueueQueue(),
            failureSender: $failureSender,
            listingException: new \RuntimeException('Listing failed.'),
        );

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(0, $result->found);
        self::assertSame(0, $result->queued);
        self::assertSame(1, $result->failed);
        self::assertSame(1, $result->transportErrors);
        self::assertSame([
            [
                'assignmentId' => '0196a222-2222-7222-8222-222222222222',
                'stage' => 'listing',
                'message' => 'Listing failed.',
                'context' => [
                    'listingUrl' => 'https://feeds.bbci.co.uk/news/world/rss.xml',
                    'exceptionClass' => \RuntimeException::class,
                ],
            ],
        ], $failureSender->failuresWithoutOccurredAt());
    }

    public function testReportsUnsupportedListingModeFailure(): void
    {
        $failureSender = new ListingEnqueueFailureSender();
        $processor = $this->processor(
            articleRefs: [],
            seenStore: new ListingEnqueueSeenStore(),
            queue: new ListingEnqueueQueue(),
            failureSender: $failureSender,
        );

        $result = $processor->process($this->assignment(listingMode: 'unsupported'), limit: 10);

        self::assertSame(0, $result->found);
        self::assertSame(0, $result->queued);
        self::assertSame(1, $result->failed);
        self::assertSame(1, $result->transportErrors);
        self::assertSame('Unsupported listing mode "unsupported".', $result->lastError);
        self::assertSame([
            [
                'assignmentId' => '0196a222-2222-7222-8222-222222222222',
                'stage' => 'listing',
                'message' => 'Unsupported listing mode "unsupported".',
                'context' => [
                    'listingUrl' => 'https://feeds.bbci.co.uk/news/world/rss.xml',
                    'exceptionClass' => \InvalidArgumentException::class,
                ],
            ],
        ], $failureSender->failuresWithoutOccurredAt());
    }

    public function testReportsMissingListingProviderFailure(): void
    {
        $failureSender = new ListingEnqueueFailureSender();
        $processor = new AssignmentListingEnqueueProcessor(
            new ArticleListingProviderRegistry([]),
            new ListingEnqueueSeenStore(),
            new ListingEnqueueQueue(),
            $failureSender,
            new NullDiagnosticLogger(),
        );

        $result = $processor->process($this->assignment(), limit: 10);

        self::assertSame(1, $result->failed);
        self::assertSame('No listing provider supports "rss_feed".', $result->lastError);
        self::assertSame([
            [
                'assignmentId' => '0196a222-2222-7222-8222-222222222222',
                'stage' => 'listing',
                'message' => 'No listing provider supports "rss_feed".',
                'context' => [
                    'listingUrl' => 'https://feeds.bbci.co.uk/news/world/rss.xml',
                    'exceptionClass' => \RuntimeException::class,
                ],
            ],
        ], $failureSender->failuresWithoutOccurredAt());
    }

    public function testLogsHtmlSelectorDiagnostics(): void
    {
        $diagnostics = new ListingEnqueueDiagnosticLogger();
        $processor = new AssignmentListingEnqueueProcessor(
            new ArticleListingProviderRegistry([
                new ListingEnqueueHtmlProvider(
                    [$this->articleRef('https://example.com/news/1')],
                    new HtmlListingSelectorStats('.news-card__link', matchedNodes: 3, uniqueUrls: 1),
                ),
            ]),
            new ListingEnqueueSeenStore(),
            new ListingEnqueueQueue(),
            new ListingEnqueueFailureSender(),
            $diagnostics,
        );

        $processor->process($this->htmlAssignment(), limit: 10);

        self::assertSame('.news-card__link', $diagnostics->contextFor('listing.start')['listingLinkSelector']);
        self::assertSame([
            'selector' => '.news-card__link',
            'matchedNodes' => 3,
            'uniqueUrls' => 1,
        ], $diagnostics->contextFor('listing.done')['htmlSelectorStats']);
    }

    public function testReportsHtmlSelectorDiagnosticsWithListingFailure(): void
    {
        $failureSender = new ListingEnqueueFailureSender();
        $diagnostics = new ListingEnqueueDiagnosticLogger();
        $processor = new AssignmentListingEnqueueProcessor(
            new ArticleListingProviderRegistry([
                new ListingEnqueueHtmlProvider(
                    [],
                    new HtmlListingSelectorStats('.news-card', matchedNodes: 5, uniqueUrls: 0),
                    new \RuntimeException('HTML listing selector matched 5 nodes but produced 0 unique URLs: ".news-card".'),
                ),
            ]),
            new ListingEnqueueSeenStore(),
            new ListingEnqueueQueue(),
            $failureSender,
            $diagnostics,
        );

        $result = $processor->process($this->htmlAssignment(), limit: 10);

        self::assertSame(1, $result->failed);
        self::assertSame([
            'selector' => '.news-card',
            'matchedNodes' => 5,
            'uniqueUrls' => 0,
        ], $diagnostics->contextFor('listing.error')['htmlSelectorStats']);
        self::assertSame([
            'selector' => '.news-card',
            'matchedNodes' => 5,
            'uniqueUrls' => 0,
        ], $failureSender->failuresWithoutOccurredAt()[0]['context']['htmlSelectorStats']);
    }

    public function testPassesAssignmentRequestTimeoutToListingSource(): void
    {
        $provider = new ListingEnqueueSourceCapturingProvider();
        $processor = new AssignmentListingEnqueueProcessor(
            new ArticleListingProviderRegistry([$provider]),
            new ListingEnqueueSeenStore(),
            new ListingEnqueueQueue(),
            new ListingEnqueueFailureSender(),
            new NullDiagnosticLogger(),
        );

        $processor->process($this->assignment(), limit: 10);

        self::assertSame([15], $provider->requestTimeouts);
    }


    /**
     * @param list<ExternalArticleRef> $articleRefs
     */
    private function processor(
        array $articleRefs,
        ListingEnqueueSeenStore $seenStore,
        ListingEnqueueQueue $queue,
        ?ListingEnqueueFailureSender $failureSender = null,
        ?\Throwable $listingException = null,
    ): AssignmentListingEnqueueProcessor {
        return new AssignmentListingEnqueueProcessor(
            new ArticleListingProviderRegistry([new ListingEnqueueProvider($articleRefs, $listingException)]),
            $seenStore,
            $queue,
            $failureSender ?? new ListingEnqueueFailureSender(),
            new NullDiagnosticLogger(),
        );
    }

    private function assignment(string $listingMode = 'rss'): ParserAssignment
    {
        return new ParserAssignment(
            assignmentId: '0196a222-2222-7222-8222-222222222222',
            sourceId: '0196a111-1111-7111-8111-111111111111',
            sourceDisplayName: 'BBC',
            listingMode: $listingMode,
            listingUrl: 'https://feeds.bbci.co.uk/news/world/rss.xml',
            articleMode: 'html',
            listingCheckIntervalSeconds: 300,
            articleFetchIntervalSeconds: 10,
            requestTimeoutSeconds: 15,
            config: [],
        );
    }

    private function htmlAssignment(): ParserAssignment
    {
        return new ParserAssignment(
            assignmentId: '0196a222-2222-7222-8222-222222222222',
            sourceId: '0196a111-1111-7111-8111-111111111111',
            sourceDisplayName: 'Example',
            listingMode: 'html',
            listingUrl: 'https://example.com/news',
            articleMode: 'html',
            listingCheckIntervalSeconds: 300,
            articleFetchIntervalSeconds: 10,
            requestTimeoutSeconds: 15,
            config: [
                'listing' => [
                    'linkSelector' => '.news-card__link',
                ],
            ],
        );
    }

    private function articleRef(string $externalUrl): ExternalArticleRef
    {
        return new ExternalArticleRef(
            externalUrl: $externalUrl,
            sourceKey: '0196a111-1111-7111-8111-111111111111',
            scopeKey: '0196a222-2222-7222-8222-222222222222',
            listingSourceType: ListingSourceType::RssFeed,
        );
    }
}

final readonly class ListingEnqueueProvider implements ArticleListingProviderInterface
{
    /**
     * @param list<ExternalArticleRef> $articleRefs
     */
    public function __construct(
        private array $articleRefs,
        private ?\Throwable $exception = null,
    ) {
    }

    public function supports(ListingSource $source): bool
    {
        return true;
    }

    public function fetchArticleRefs(ListingSource $source): iterable
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->articleRefs;
    }
}

final readonly class ListingEnqueueHtmlProvider implements ArticleListingProviderInterface, HtmlListingSelectorStatsProviderInterface
{
    /**
     * @param list<ExternalArticleRef> $articleRefs
     */
    public function __construct(
        private array $articleRefs,
        private HtmlListingSelectorStats $stats,
        private ?\Throwable $exception = null,
    ) {
    }

    public function supports(ListingSource $source): bool
    {
        return $source->type === ListingSourceType::HtmlSection;
    }

    public function fetchArticleRefs(ListingSource $source): iterable
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->articleRefs;
    }

    public function lastSelectorStats(): ?HtmlListingSelectorStats
    {
        return $this->stats;
    }
}

final class ListingEnqueueSourceCapturingProvider implements ArticleListingProviderInterface
{
    /**
     * @var list<int|null>
     */
    public array $requestTimeouts = [];

    public function supports(ListingSource $source): bool
    {
        return true;
    }

    public function fetchArticleRefs(ListingSource $source): iterable
    {
        $this->requestTimeouts[] = $source->requestTimeoutSeconds;

        return [];
    }
}

final class ListingEnqueueDiagnosticLogger implements DiagnosticLoggerInterface
{
    /**
     * @var list<array{event: string, context: array<string, mixed>}>
     */
    public array $records = [];

    public function log(string $event, array $context = []): void
    {
        $this->records[] = [
            'event' => $event,
            'context' => $context,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function contextFor(string $event): array
    {
        foreach ($this->records as $record) {
            if ($record['event'] === $event) {
                return $record['context'];
            }
        }

        throw new \RuntimeException(sprintf('Diagnostic event "%s" was not recorded.', $event));
    }
}

final readonly class ListingEnqueueSeenStore implements SeenArticleStoreInterface
{
    /**
     * @param list<string> $seenUrls
     */
    public function __construct(
        private array $seenUrls = [],
    ) {
    }

    public function has(string $externalUrl): bool
    {
        return \in_array($externalUrl, $this->seenUrls, true);
    }

    public function markSeen(string $externalUrl, string $sourceKey, string $scopeKey): void
    {
    }

    public function markParsed(string $externalUrl): void
    {
    }

    public function markFailed(string $externalUrl, string $error): void
    {
    }
}

final class ListingEnqueueQueue implements PendingArticleQueueInterface
{
    /**
     * @var list<array{assignmentId: string, externalUrl: string, sourceKey: string}>
     */
    public array $enqueued = [];

    public function enqueue(string $assignmentId, string $externalUrl, string $sourceKey): bool
    {
        $this->enqueued[] = [
            'assignmentId' => $assignmentId,
            'externalUrl' => $externalUrl,
            'sourceKey' => $sourceKey,
        ];

        return true;
    }

    public function takePending(string $assignmentId, int $limit): array
    {
        return [];
    }

    public function markSent(string $assignmentId, string $externalUrl): void
    {
    }

    public function markFailed(string $assignmentId, string $externalUrl, string $error): void
    {
    }
}

final class ListingEnqueueFailureSender implements MainApiParserFailureSenderInterface
{
    /**
     * @var list<array{assignmentId: string, stage: string, message: string, context: array<string, mixed>, occurredAt: string}>
     */
    public array $failures = [];

    public function send(
        string $assignmentId,
        string $stage,
        string $message,
        array $context,
        \DateTimeImmutable $occurredAt,
    ): void {
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

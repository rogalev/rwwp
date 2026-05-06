<?php

declare(strict_types=1);

namespace App\Tests\Pipeline;

use App\Http\DocumentFetcherInterface;
use App\Http\FetchedDocument;
use App\Listing\ArticleListingProviderInterface;
use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ListingSource;
use App\MainApi\MainApiRawArticleSenderInterface;
use App\MainApi\ParserAssignment;
use App\MainApi\SendRawArticleResult;
use App\Pipeline\AssignmentArticleFetchProcessor;
use App\Pipeline\AssignmentListingEnqueueProcessor;
use App\Pipeline\AssignmentTimeoutException;
use App\Pipeline\PcntlAssignmentProcessorGuard;
use App\Pipeline\ScheduledAssignmentProcessor;
use App\Schedule\AssignmentScheduleDecision;
use App\State\SeenArticleStoreInterface;
use App\Tests\Support\InMemoryPendingArticleQueue;
use App\Tests\Support\NullDiagnosticLogger;
use App\Tests\Support\NullParserFailureSender;
use PHPUnit\Framework\TestCase;

final class PcntlAssignmentProcessorGuardTest extends TestCase
{
    public function testKillsHungAssignmentProcess(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_waitpid') || !\function_exists('posix_kill')) {
            self::markTestSkipped('pcntl/posix functions are required for this test.');
        }

        $guard = new PcntlAssignmentProcessorGuard(
            new ScheduledAssignmentProcessor(
                new AssignmentListingEnqueueProcessor(
                    new ArticleListingProviderRegistry([new HangingListingProvider()]),
                    new PcntlSeenStore(),
                    new InMemoryPendingArticleQueue(),
                    new NullParserFailureSender(),
                    new NullDiagnosticLogger(),
                ),
                new AssignmentArticleFetchProcessor(
                    new InMemoryPendingArticleQueue(),
                    new PcntlDocumentFetcher(),
                    new PcntlRawArticleSender(),
                    new NullParserFailureSender(),
                    new PcntlSeenStore(),
                    new NullDiagnosticLogger(),
                ),
            ),
            timeoutSeconds: 1,
        );

        $this->expectException(AssignmentTimeoutException::class);
        $this->expectExceptionMessage('Assignment "assignment-timeout" timed out after 1 seconds.');

        $guard->process(
            new ParserAssignment(
                assignmentId: 'assignment-timeout',
                sourceId: 'source-id',
                sourceDisplayName: 'Timeout source',
                listingMode: 'rss',
                listingUrl: 'https://example.com/rss.xml',
                articleMode: 'html',
                listingCheckIntervalSeconds: 300,
                articleFetchIntervalSeconds: 10,
                requestTimeoutSeconds: 15,
                config: [],
            ),
            new AssignmentScheduleDecision(listingDue: true, articleFetchDue: false),
            limit: 1,
        );
    }
}

final readonly class HangingListingProvider implements ArticleListingProviderInterface
{
    public function supports(ListingSource $source): bool
    {
        return true;
    }

    public function fetchArticleRefs(ListingSource $source): iterable
    {
        sleep(5);

        return [];
    }
}

final readonly class PcntlDocumentFetcher implements DocumentFetcherInterface
{
    public function fetch(string $url, array $headers = [], ?float $timeout = null): FetchedDocument
    {
        throw new \RuntimeException('Not expected.');
    }
}

final readonly class PcntlRawArticleSender implements MainApiRawArticleSenderInterface
{
    public function send(
        string $assignmentId,
        string $externalUrl,
        string $rawHtml,
        int $httpStatusCode,
        \DateTimeImmutable $fetchedAt,
    ): SendRawArticleResult {
        throw new \RuntimeException('Not expected.');
    }
}

final class PcntlSeenStore implements SeenArticleStoreInterface
{
    public function has(string $externalUrl): bool
    {
        return false;
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

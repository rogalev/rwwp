<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AssignmentRunOnceCommand;
use App\Http\DocumentFetcherInterface;
use App\Http\FetchedDocument;
use App\Listing\ArticleListingProviderInterface;
use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ExternalArticleRef;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\MainApi\MainApiAssignmentsProviderInterface;
use App\MainApi\MainApiParserFailureSenderInterface;
use App\MainApi\MainApiRawArticleSenderInterface;
use App\MainApi\ParserAssignment;
use App\MainApi\SendRawArticleResult;
use App\Pipeline\AssignmentArticleFetchProcessor;
use App\Pipeline\AssignmentListingEnqueueProcessor;
use App\Pipeline\ScheduledAssignmentProcessor;
use App\Schedule\AssignmentScheduleDecider;
use App\State\SeenArticleStoreInterface;
use App\Tests\Support\InMemoryAssignmentScheduleStore;
use App\Tests\Support\InMemoryPendingArticleQueue;
use App\Tests\Support\NullDiagnosticLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AssignmentRunOnceCommandTest extends TestCase
{
    public function testRunsSingleAssignmentThroughScheduledPipeline(): void
    {
        $scheduleStore = new InMemoryAssignmentScheduleStore();
        $assignment = $this->assignment('0196a222-2222-7222-8222-222222222222', 'BBC');
        $commandTester = new CommandTester($this->command([$assignment], $scheduleStore));

        $exitCode = $commandTester->execute([
            'assignmentId' => $assignment->assignmentId,
            '--limit' => '1',
        ]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString($assignment->assignmentId, $display);
        self::assertStringContainsString('BBC', $display);
        self::assertStringContainsString('raw_article_send', $display);
        self::assertStringContainsString('Queued', $display);
        self::assertNotNull($scheduleStore->lastListingCheckedAt($assignment->assignmentId));
        self::assertNotNull($scheduleStore->lastArticleFetchedAt($assignment->assignmentId));
    }

    public function testSkipsAssignmentWhenScheduleIsNotDue(): void
    {
        $scheduleStore = new InMemoryAssignmentScheduleStore();
        $assignment = $this->assignment('0196a222-2222-7222-8222-222222222222', 'BBC');
        $scheduleStore->markListingChecked($assignment->assignmentId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $scheduleStore->markArticleFetched($assignment->assignmentId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $commandTester = new CommandTester($this->command([$assignment], $scheduleStore));

        $exitCode = $commandTester->execute([
            'assignmentId' => $assignment->assignmentId,
            '--limit' => '1',
        ]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('idle', $display);
        self::assertStringContainsString('Skipped', $display);
    }

    public function testFailsWhenAssignmentDoesNotExist(): void
    {
        $commandTester = new CommandTester($this->command([], new InMemoryAssignmentScheduleStore()));

        $exitCode = $commandTester->execute([
            'assignmentId' => '0196a999-9999-7999-8999-999999999999',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Assignment not found: 0196a999-9999-7999-8999-999999999999', $commandTester->getDisplay());
    }

    public function testFailsOnInvalidLimit(): void
    {
        $assignment = $this->assignment('0196a222-2222-7222-8222-222222222222', 'BBC');
        $commandTester = new CommandTester($this->command([$assignment], new InMemoryAssignmentScheduleStore()));

        $exitCode = $commandTester->execute([
            'assignmentId' => $assignment->assignmentId,
            '--limit' => '0',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('limit must be greater than zero.', $commandTester->getDisplay());
    }

    /**
     * @param list<ParserAssignment> $assignments
     */
    private function command(array $assignments, InMemoryAssignmentScheduleStore $scheduleStore): AssignmentRunOnceCommand
    {
        $queue = new InMemoryPendingArticleQueue();
        $failureSender = new AssignmentRunOnceFailureSender();
        $seenStore = new AssignmentRunOnceSeenStore();

        return new AssignmentRunOnceCommand(
            new AssignmentRunOnceAssignmentsProvider($assignments),
            new AssignmentScheduleDecider($scheduleStore),
            $scheduleStore,
            new ScheduledAssignmentProcessor(
                new AssignmentListingEnqueueProcessor(
                    new ArticleListingProviderRegistry([new AssignmentRunOnceListingProvider()]),
                    $seenStore,
                    $queue,
                    $failureSender,
                    new NullDiagnosticLogger(),
                ),
                new AssignmentArticleFetchProcessor(
                    $queue,
                    new AssignmentRunOnceDocumentFetcher(),
                    new AssignmentRunOnceRawArticleSender(),
                    $failureSender,
                    $seenStore,
                    new NullDiagnosticLogger(),
                ),
            ),
        );
    }

    private function assignment(string $assignmentId, string $sourceDisplayName): ParserAssignment
    {
        return new ParserAssignment(
            assignmentId: $assignmentId,
            sourceId: '0196a111-1111-7111-8111-111111111111',
            sourceDisplayName: $sourceDisplayName,
            listingMode: 'rss',
            listingUrl: 'https://feeds.example.com/news/rss.xml',
            articleMode: 'html',
            listingCheckIntervalSeconds: 300,
            articleFetchIntervalSeconds: 10,
            requestTimeoutSeconds: 15,
            config: [],
        );
    }
}

final readonly class AssignmentRunOnceAssignmentsProvider implements MainApiAssignmentsProviderInterface
{
    /**
     * @param list<ParserAssignment> $assignments
     */
    public function __construct(
        private array $assignments,
    ) {
    }

    public function list(): array
    {
        return $this->assignments;
    }
}

final readonly class AssignmentRunOnceListingProvider implements ArticleListingProviderInterface
{
    public function supports(ListingSource $source): bool
    {
        return true;
    }

    public function fetchArticleRefs(ListingSource $source): iterable
    {
        return [
            new ExternalArticleRef(
                externalUrl: 'https://example.com/news/'.substr($source->categoryCode, 0, 8),
                sourceCode: $source->sourceCode,
                categoryCode: $source->categoryCode,
                listingSourceType: ListingSourceType::RssFeed,
            ),
        ];
    }
}

final readonly class AssignmentRunOnceDocumentFetcher implements DocumentFetcherInterface
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

final readonly class AssignmentRunOnceRawArticleSender implements MainApiRawArticleSenderInterface
{
    public function send(
        string $assignmentId,
        string $externalUrl,
        string $rawHtml,
        int $httpStatusCode,
        \DateTimeImmutable $fetchedAt,
    ): SendRawArticleResult {
        return new SendRawArticleResult(
            id: '0196a444-4444-7444-8444-444444444444',
            created: true,
            externalUrl: $externalUrl,
            contentHash: 'content-hash',
        );
    }
}

final readonly class AssignmentRunOnceFailureSender implements MainApiParserFailureSenderInterface
{
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
    }
}

final class AssignmentRunOnceSeenStore implements SeenArticleStoreInterface
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

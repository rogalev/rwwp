<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AssignmentsProcessCommand;
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
use App\Pipeline\AssignmentsBatchProcessor;
use App\Pipeline\DirectAssignmentProcessorGuard;
use App\Pipeline\ScheduledAssignmentProcessor;
use App\Schedule\AssignmentScheduleDecider;
use App\State\SeenArticleStoreInterface;
use App\Status\ParserRunStatusHeartbeatPayloadFactory;
use App\Status\ParserRunStatusWriter;
use App\Tests\Support\InMemoryAssignmentScheduleStore;
use App\Tests\Support\InMemoryPendingArticleQueue;
use App\Tests\Support\NullDiagnosticLogger;
use App\Tests\Support\NullHeartbeatSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AssignmentsProcessCommandTest extends TestCase
{
    public function testProcessesAllAssignments(): void
    {
        $statusPath = $this->temporaryStatusPath();
        $processor = $this->processor();
        $commandTester = new CommandTester(new AssignmentsProcessCommand(
            $this->batchProcessor(
                [
                    $this->assignment('0196a222-2222-7222-8222-222222222222', 'BBC'),
                    $this->assignment('0196a333-3333-7333-8333-333333333333', 'CNN'),
                ],
                $processor,
                $statusPath,
            ),
        ));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '1']);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('0196a222-2222-7222-8222-222222222222', $display);
        self::assertStringContainsString('0196a333-3333-7333-8333-333333333333', $display);
        self::assertStringContainsString('BBC', $display);
        self::assertStringContainsString('CNN', $display);

        $status = $this->readStatus($statusPath);
        self::assertSame('main_assignments_batch', $status['mode']);
        self::assertSame(2, $status['assignments']);
        self::assertSame(2, $status['found']);
        self::assertSame(0, $status['alreadySeen']);
        self::assertSame(2, $status['queued']);
        self::assertSame(2, $status['sent']);
        self::assertSame(0, $status['failed']);
        self::assertSame([200 => 2], $status['httpStatusCodes']);
        self::assertSame(0, $status['transportErrors']);
        self::assertSame([], $status['assignmentErrors']);
        self::assertSame('', $status['lastError']);
    }

    public function testShowsEmptyAssignmentsMessage(): void
    {
        $statusPath = $this->temporaryStatusPath();
        $commandTester = new CommandTester(new AssignmentsProcessCommand(
            $this->batchProcessor([], $this->processor(), $statusPath),
        ));

        $exitCode = $commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Назначения для текущего parser-agent не найдены.', $commandTester->getDisplay());

        $status = $this->readStatus($statusPath);
        self::assertSame(0, $status['assignments']);
        self::assertSame(0, $status['found']);
        self::assertSame(0, $status['queued']);
        self::assertSame(0, $status['sent']);
        self::assertSame([], $status['httpStatusCodes']);
        self::assertSame(0, $status['transportErrors']);
        self::assertSame('', $status['lastError']);
    }

    public function testFailsOnInvalidLimit(): void
    {
        $commandTester = new CommandTester(new AssignmentsProcessCommand(
            $this->batchProcessor([], $this->processor(), $this->temporaryStatusPath()),
        ));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '0']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('limit-per-assignment must be greater than zero.', $commandTester->getDisplay());
    }

    public function testContinuesWhenOneAssignmentFails(): void
    {
        $statusPath = $this->temporaryStatusPath();
        $commandTester = new CommandTester(new AssignmentsProcessCommand(
            $this->batchProcessor(
                [
                    $this->assignment('0196a222-2222-7222-8222-222222222222', 'Broken source'),
                    $this->assignment('0196a333-3333-7333-8333-333333333333', 'Working source'),
                ],
                $this->processor(failingAssignmentId: '0196a222-2222-7222-8222-222222222222'),
                $statusPath,
            ),
        ));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '1']);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Broken source failed.', $display);
        self::assertStringContainsString('Working source', $display);

        $status = $this->readStatus($statusPath);
        self::assertSame(2, $status['assignments']);
        self::assertSame(1, $status['found']);
        self::assertSame(1, $status['queued']);
        self::assertSame(1, $status['sent']);
        self::assertSame([200 => 1], $status['httpStatusCodes']);
        self::assertSame(1, $status['transportErrors']);
        self::assertSame([
            [
                'assignmentId' => '0196a222-2222-7222-8222-222222222222',
                'source' => 'Broken source',
                'error' => 'Broken source failed.',
            ],
        ], $status['assignmentErrors']);
        self::assertSame('Broken source failed.', $status['lastError']);
    }

    public function testSkipsAssignmentWhenScheduleIsNotDue(): void
    {
        $statusPath = $this->temporaryStatusPath();
        $scheduleStore = new InMemoryAssignmentScheduleStore();
        $assignment = $this->assignment('0196a222-2222-7222-8222-222222222222', 'BBC');
        $scheduleStore->markListingChecked($assignment->assignmentId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $scheduleStore->markArticleFetched($assignment->assignmentId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $commandTester = new CommandTester(new AssignmentsProcessCommand(
            $this->batchProcessor(
                [$assignment],
                $this->processor(),
                $statusPath,
                $scheduleStore,
            ),
        ));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '1']);

        self::assertSame(Command::SUCCESS, $exitCode);

        $status = $this->readStatus($statusPath);
        self::assertSame(1, $status['assignments']);
        self::assertSame(1, $status['skippedAssignments']);
        self::assertSame(0, $status['found']);
        self::assertSame(0, $status['queued']);
        self::assertSame(0, $status['sent']);
        self::assertSame('idle', $status['stage']);
    }

    private function processor(?string $failingAssignmentId = null): ScheduledAssignmentProcessor
    {
        $queue = new InMemoryPendingArticleQueue();
        $failureSender = new AssignmentsProcessFailureSender();
        $seenStore = new AssignmentsProcessSeenStore();

        return new ScheduledAssignmentProcessor(
            new AssignmentListingEnqueueProcessor(
                new ArticleListingProviderRegistry([new AssignmentsProcessListingProvider($failingAssignmentId)]),
                $seenStore,
                $queue,
                $failureSender,
                new NullDiagnosticLogger(),
            ),
            new AssignmentArticleFetchProcessor(
                $queue,
                new AssignmentsProcessDocumentFetcher(),
                new AssignmentsProcessRawArticleSender(),
                $failureSender,
                $seenStore,
                new NullDiagnosticLogger(),
            ),
        );
    }

    /**
     * @param list<ParserAssignment> $assignments
     */
    private function batchProcessor(
        array $assignments,
        ScheduledAssignmentProcessor $processor,
        string $statusPath,
        ?InMemoryAssignmentScheduleStore $scheduleStore = null,
    ): AssignmentsBatchProcessor {
        $scheduleStore ??= new InMemoryAssignmentScheduleStore();

        return new AssignmentsBatchProcessor(
            new AssignmentsProcessAssignmentsProvider($assignments),
            new DirectAssignmentProcessorGuard($processor),
            new ParserRunStatusWriter($statusPath),
            new AssignmentScheduleDecider($scheduleStore),
            $scheduleStore,
            new ParserRunStatusHeartbeatPayloadFactory(),
            new NullHeartbeatSender(),
            new AssignmentsProcessFailureSender(),
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

    private function temporaryStatusPath(): string
    {
        return sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/status/parser-run.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function readStatus(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}

final readonly class AssignmentsProcessAssignmentsProvider implements MainApiAssignmentsProviderInterface
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

final readonly class AssignmentsProcessListingProvider implements ArticleListingProviderInterface
{
    public function __construct(
        private ?string $failingAssignmentId = null,
    ) {
    }

    public function supports(ListingSource $source): bool
    {
        return true;
    }

    public function fetchArticleRefs(ListingSource $source): iterable
    {
        if ($source->scopeKey === $this->failingAssignmentId) {
            throw new \RuntimeException('Broken source failed.');
        }

        return [
            new ExternalArticleRef(
                externalUrl: 'https://example.com/news/'.substr($source->scopeKey, 0, 8),
                sourceKey: $source->sourceKey,
                scopeKey: $source->scopeKey,
                listingSourceType: ListingSourceType::RssFeed,
            ),
        ];
    }
}

final readonly class AssignmentsProcessDocumentFetcher implements DocumentFetcherInterface
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

final readonly class AssignmentsProcessRawArticleSender implements MainApiRawArticleSenderInterface
{
    public function send(
        string $assignmentId,
        string $externalUrl,
        string $rawHtml,
        int $httpStatusCode,
        \DateTimeImmutable $fetchedAt,
    ): SendRawArticleResult {
        return new SendRawArticleResult(
            jobId: '0196a444-4444-7444-8444-444444444444',
            accepted: true,
            externalUrl: $externalUrl,
            status: 'pending',
        );
    }
}

final readonly class AssignmentsProcessFailureSender implements MainApiParserFailureSenderInterface
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

final class AssignmentsProcessSeenStore implements SeenArticleStoreInterface
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

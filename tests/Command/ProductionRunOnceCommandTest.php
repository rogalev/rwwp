<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ProductionRunOnceCommand;
use App\Http\DocumentFetcherInterface;
use App\Http\FetchedDocument;
use App\Listing\ArticleListingProviderInterface;
use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ExternalArticleRef;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\MainApi\MainApiAssignmentsProviderInterface;
use App\MainApi\AssignmentRunStats;
use App\MainApi\MainApiAssignmentRunsSenderInterface;
use App\MainApi\MainApiHeartbeatSenderInterface;
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
use App\Status\ParserRunStatusReader;
use App\Status\ParserRunStatusWriter;
use App\Tests\Support\InMemoryAssignmentScheduleStore;
use App\Tests\Support\InMemoryPendingArticleQueue;
use App\Tests\Support\NullDiagnosticLogger;
use App\Tests\Support\NullHeartbeatSender;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ProductionRunOnceCommandTest extends TestCase
{
    public function testProcessesAssignmentsAndSendsHeartbeat(): void
    {
        $statusPath = $this->temporaryStatusPath();
        $heartbeatSender = new ProductionRecordingHeartbeatSender();
        $assignmentRunsSender = new ProductionRecordingAssignmentRunsSender();
        $commandTester = new CommandTester($this->command(
            assignments: [$this->assignment('0196a222-2222-7222-8222-222222222222', 'BBC')],
            statusPath: $statusPath,
            heartbeatSender: $heartbeatSender,
            assignmentRunsSender: $assignmentRunsSender,
        ));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '1']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('ok', $heartbeatSender->status);
        self::assertSame('', $heartbeatSender->message);
        self::assertSame(1, $heartbeatSender->metrics['foundLinks']);
        self::assertSame(1, $heartbeatSender->metrics['queuedArticles']);
        self::assertSame(1, $heartbeatSender->metrics['acceptedRawArticles']);
        self::assertSame(['200' => 1], $heartbeatSender->metrics['httpStatusCodes']);
        self::assertNotNull($assignmentRunsSender->checkedAt);
        self::assertCount(1, $assignmentRunsSender->items);
        self::assertSame('0196a222-2222-7222-8222-222222222222', $assignmentRunsSender->items[0]->assignmentId);
        self::assertSame('raw_article_send', $assignmentRunsSender->items[0]->stage);
        self::assertSame('ok', $assignmentRunsSender->items[0]->status);
        self::assertSame(1, $assignmentRunsSender->items[0]->found);
        self::assertSame(1, $assignmentRunsSender->items[0]->queued);
        self::assertSame(1, $assignmentRunsSender->items[0]->sent);
        self::assertFalse($assignmentRunsSender->items[0]->skipped);
        self::assertSame([200 => 1], $assignmentRunsSender->items[0]->httpStatusCodes);
        self::assertGreaterThanOrEqual(0, $assignmentRunsSender->items[0]->durationMs);
        self::assertStringContainsString('Статистика назначений отправлена в main.', $commandTester->getDisplay());
        self::assertStringContainsString('Heartbeat отправлен в main.', $commandTester->getDisplay());
    }

    public function testSendsErrorHeartbeatAndReturnsFailureWhenAssignmentFails(): void
    {
        $statusPath = $this->temporaryStatusPath();
        $heartbeatSender = new ProductionRecordingHeartbeatSender();
        $commandTester = new CommandTester($this->command(
            assignments: [$this->assignment('0196a222-2222-7222-8222-222222222222', 'BBC')],
            statusPath: $statusPath,
            heartbeatSender: $heartbeatSender,
            assignmentRunsSender: new ProductionRecordingAssignmentRunsSender(),
            failingAssignmentId: '0196a222-2222-7222-8222-222222222222',
        ));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '1']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame('error', $heartbeatSender->status);
        self::assertSame('Assignment failed.', $heartbeatSender->message);
        self::assertSame(1, $heartbeatSender->metrics['transportErrors']);
    }

    public function testReturnsFailureWhenHeartbeatFails(): void
    {
        $statusPath = $this->temporaryStatusPath();
        $commandTester = new CommandTester($this->command(
            assignments: [$this->assignment('0196a222-2222-7222-8222-222222222222', 'BBC')],
            statusPath: $statusPath,
            heartbeatSender: new ProductionFailingHeartbeatSender(new \RuntimeException('Heartbeat rejected.')),
            assignmentRunsSender: new ProductionRecordingAssignmentRunsSender(),
        ));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '1']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Heartbeat rejected.', $commandTester->getDisplay());
    }

    public function testReturnsFailureWhenAssignmentRunsSendingFails(): void
    {
        $statusPath = $this->temporaryStatusPath();
        $commandTester = new CommandTester($this->command(
            assignments: [$this->assignment('0196a222-2222-7222-8222-222222222222', 'BBC')],
            statusPath: $statusPath,
            heartbeatSender: new ProductionRecordingHeartbeatSender(),
            assignmentRunsSender: new ProductionFailingAssignmentRunsSender(new \RuntimeException('Assignment stats rejected.')),
        ));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '1']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Assignment stats rejected.', $commandTester->getDisplay());
    }

    /**
     * @param list<ParserAssignment> $assignments
     */
    private function command(
        array $assignments,
        string $statusPath,
        MainApiHeartbeatSenderInterface $heartbeatSender,
        MainApiAssignmentRunsSenderInterface $assignmentRunsSender,
        ?string $failingAssignmentId = null,
    ): ProductionRunOnceCommand {
        $scheduleStore = new InMemoryAssignmentScheduleStore();
        $queue = new InMemoryPendingArticleQueue();
        $failureSender = new ProductionFailureSender();
        $seenStore = new ProductionSeenStore();

        return new ProductionRunOnceCommand(
            new AssignmentsBatchProcessor(
                new ProductionAssignmentsProvider($assignments),
                new DirectAssignmentProcessorGuard(new ScheduledAssignmentProcessor(
                    new AssignmentListingEnqueueProcessor(
                        new ArticleListingProviderRegistry([new ProductionListingProvider($failingAssignmentId)]),
                        $seenStore,
                        $queue,
                        $failureSender,
                        new NullDiagnosticLogger(),
                    ),
                    new AssignmentArticleFetchProcessor(
                        $queue,
                        new ProductionDocumentFetcher(),
                        new ProductionRawArticleSender(),
                        $failureSender,
                        $seenStore,
                        new NullDiagnosticLogger(),
                    ),
                )),
                new ParserRunStatusWriter($statusPath),
                new AssignmentScheduleDecider($scheduleStore),
                $scheduleStore,
                new ParserRunStatusHeartbeatPayloadFactory(),
                new NullHeartbeatSender(),
                $failureSender,
            ),
            new ParserRunStatusReader($statusPath),
            new ParserRunStatusHeartbeatPayloadFactory(),
            $heartbeatSender,
            $assignmentRunsSender,
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
}

final readonly class ProductionAssignmentsProvider implements MainApiAssignmentsProviderInterface
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

final readonly class ProductionListingProvider implements ArticleListingProviderInterface
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
            throw new \RuntimeException('Assignment failed.');
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

final readonly class ProductionDocumentFetcher implements DocumentFetcherInterface
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

final readonly class ProductionRawArticleSender implements MainApiRawArticleSenderInterface
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

final readonly class ProductionFailureSender implements MainApiParserFailureSenderInterface
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

final class ProductionSeenStore implements SeenArticleStoreInterface
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

final class ProductionRecordingHeartbeatSender implements MainApiHeartbeatSenderInterface
{
    public ?string $status = null;
    public ?string $message = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $metrics = null;

    public function send(\DateTimeImmutable $checkedAt, string $status, string $message, array $metrics): void
    {
        $this->status = $status;
        $this->message = $message;
        $this->metrics = $metrics;
    }
}

final class ProductionRecordingAssignmentRunsSender implements MainApiAssignmentRunsSenderInterface
{
    public ?\DateTimeImmutable $checkedAt = null;

    /**
     * @var list<AssignmentRunStats>
     */
    public array $items = [];

    public function send(\DateTimeImmutable $checkedAt, array $items): void
    {
        $this->checkedAt = $checkedAt;
        $this->items = $items;
    }
}

final readonly class ProductionFailingAssignmentRunsSender implements MainApiAssignmentRunsSenderInterface
{
    public function __construct(
        private \Throwable $exception,
    ) {
    }

    public function send(\DateTimeImmutable $checkedAt, array $items): void
    {
        throw $this->exception;
    }
}

final readonly class ProductionFailingHeartbeatSender implements MainApiHeartbeatSenderInterface
{
    public function __construct(
        private \Throwable $exception,
    ) {
    }

    public function send(\DateTimeImmutable $checkedAt, string $status, string $message, array $metrics): void
    {
        throw $this->exception;
    }
}

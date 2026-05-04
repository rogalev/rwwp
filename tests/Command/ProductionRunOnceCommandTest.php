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
use App\MainApi\MainApiHeartbeatSenderInterface;
use App\MainApi\MainApiParserFailureSenderInterface;
use App\MainApi\MainApiRawArticleSenderInterface;
use App\MainApi\ParserAssignment;
use App\MainApi\SendRawArticleResult;
use App\Pipeline\AssignmentRawArticleProcessor;
use App\Pipeline\AssignmentsBatchProcessor;
use App\Schedule\AssignmentScheduleDecider;
use App\State\SeenArticleStoreInterface;
use App\Status\ParserRunStatusHeartbeatPayloadFactory;
use App\Status\ParserRunStatusReader;
use App\Status\ParserRunStatusWriter;
use App\Tests\Support\InMemoryAssignmentScheduleStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ProductionRunOnceCommandTest extends TestCase
{
    public function testProcessesAssignmentsAndSendsHeartbeat(): void
    {
        $statusPath = $this->temporaryStatusPath();
        $heartbeatSender = new ProductionRecordingHeartbeatSender();
        $commandTester = new CommandTester($this->command(
            assignments: [$this->assignment('0196a222-2222-7222-8222-222222222222', 'BBC')],
            statusPath: $statusPath,
            heartbeatSender: $heartbeatSender,
        ));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '1']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('ok', $heartbeatSender->status);
        self::assertSame('', $heartbeatSender->message);
        self::assertSame(1, $heartbeatSender->metrics['foundLinks']);
        self::assertSame(1, $heartbeatSender->metrics['acceptedRawArticles']);
        self::assertSame(['200' => 1], $heartbeatSender->metrics['httpStatusCodes']);
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
        ));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '1']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Heartbeat rejected.', $commandTester->getDisplay());
    }

    /**
     * @param list<ParserAssignment> $assignments
     */
    private function command(
        array $assignments,
        string $statusPath,
        MainApiHeartbeatSenderInterface $heartbeatSender,
        ?string $failingAssignmentId = null,
    ): ProductionRunOnceCommand {
        $scheduleStore = new InMemoryAssignmentScheduleStore();

        return new ProductionRunOnceCommand(
            new AssignmentsBatchProcessor(
                new ProductionAssignmentsProvider($assignments),
                new AssignmentRawArticleProcessor(
                    new ArticleListingProviderRegistry([new ProductionListingProvider($failingAssignmentId)]),
                    new ProductionDocumentFetcher(),
                    new ProductionRawArticleSender(),
                    new ProductionFailureSender(),
                    new ProductionSeenStore(),
                ),
                new ParserRunStatusWriter($statusPath),
                new AssignmentScheduleDecider($scheduleStore),
                $scheduleStore,
            ),
            new ParserRunStatusReader($statusPath),
            new ParserRunStatusHeartbeatPayloadFactory(),
            $heartbeatSender,
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
        if ($source->categoryCode === $this->failingAssignmentId) {
            throw new \RuntimeException('Assignment failed.');
        }

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

final readonly class ProductionDocumentFetcher implements DocumentFetcherInterface
{
    public function fetch(string $url, array $headers = []): FetchedDocument
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
            id: '0196a444-4444-7444-8444-444444444444',
            created: true,
            externalUrl: $externalUrl,
            contentHash: 'content-hash',
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

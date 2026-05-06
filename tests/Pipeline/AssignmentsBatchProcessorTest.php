<?php

declare(strict_types=1);

namespace App\Tests\Pipeline;

use App\MainApi\MainApiAssignmentsProviderInterface;
use App\MainApi\MainApiHeartbeatSenderInterface;
use App\MainApi\MainApiParserFailureSenderInterface;
use App\MainApi\ParserAssignment;
use App\Pipeline\AssignmentProcessorGuardInterface;
use App\Pipeline\AssignmentsBatchProcessor;
use App\Pipeline\AssignmentTimeoutException;
use App\Pipeline\ScheduledAssignmentProcessingResult;
use App\Schedule\AssignmentScheduleDecision;
use App\Schedule\AssignmentScheduleDecider;
use App\Status\ParserRunStatusHeartbeatPayloadFactory;
use App\Status\ParserRunStatusWriter;
use App\Tests\Support\InMemoryAssignmentScheduleStore;
use PHPUnit\Framework\TestCase;

final class AssignmentsBatchProcessorTest extends TestCase
{
    public function testTimeoutDoesNotStopNextAssignmentAndReportsProgress(): void
    {
        $statusPath = $this->temporaryStatusPath();
        $assignments = [
            $this->assignment('assignment-timeout', 'Timeout source'),
            $this->assignment('assignment-ok', 'Working source'),
        ];
        $guard = new BatchProcessorGuard([
            'assignment-timeout' => new AssignmentTimeoutException(
                assignmentId: 'assignment-timeout',
                source: 'Timeout source',
                timeoutSeconds: 120,
                stage: 'listing',
            ),
            'assignment-ok' => new ScheduledAssignmentProcessingResult(
                found: 2,
                alreadySeen: 1,
                queued: 1,
                sent: 1,
                failed: 0,
                httpStatusCodes: [200 => 1],
                transportErrors: 0,
                stage: 'raw_article_send',
            ),
        ]);
        $heartbeatSender = new BatchProcessorHeartbeatSender();
        $failureSender = new BatchProcessorFailureSender();

        $result = $this->processor($assignments, $guard, $statusPath, $heartbeatSender, $failureSender)
            ->process(limitPerAssignment: 1);

        self::assertSame(['assignment-timeout', 'assignment-ok'], $guard->processedAssignmentIds);
        self::assertSame(2, $result->assignments);
        self::assertSame(2, $result->processedAssignments);
        self::assertSame(1, $result->timedOutAssignments);
        self::assertSame(1, $result->failed);
        self::assertSame(1, $result->sent);
        self::assertSame('Assignment "assignment-timeout" timed out after 120 seconds.', $result->lastError);

        self::assertCount(3, $heartbeatSender->payloads);
        self::assertSame(1, $heartbeatSender->payloads[0]['metrics']['processedAssignments']);
        self::assertSame(1, $heartbeatSender->payloads[0]['metrics']['timedOutAssignments']);
        self::assertSame('assignment-timeout', $heartbeatSender->payloads[0]['metrics']['currentAssignmentId']);
        self::assertSame(2, $heartbeatSender->payloads[1]['metrics']['processedAssignments']);
        self::assertSame('assignment-ok', $heartbeatSender->payloads[1]['metrics']['currentAssignmentId']);

        self::assertSame([
            [
                'assignmentId' => 'assignment-timeout',
                'stage' => 'listing',
                'message' => 'Assignment "assignment-timeout" timed out after 120 seconds.',
                'context' => [
                    'assignmentId' => 'assignment-timeout',
                    'source' => 'Timeout source',
                    'timeoutSeconds' => 120,
                    'stage' => 'listing',
                ],
            ],
        ], $failureSender->failures);

        $status = $this->readStatus($statusPath);
        self::assertSame(2, $status['processedAssignments']);
        self::assertSame(1, $status['timedOutAssignments']);
        self::assertSame(1, $status['sent']);
        self::assertSame(1, $status['failed']);
        self::assertSame('Assignment "assignment-timeout" timed out after 120 seconds.', $status['lastError']);
        self::assertSame([
            [
                'assignmentId' => 'assignment-timeout',
                'source' => 'Timeout source',
                'error' => 'Assignment "assignment-timeout" timed out after 120 seconds.',
            ],
        ], $status['assignmentErrors']);
    }

    public function testHeartbeatFailureDoesNotBreakBatch(): void
    {
        $statusPath = $this->temporaryStatusPath();
        $heartbeatSender = new BatchProcessorHeartbeatSender(new \RuntimeException('Heartbeat rejected.'));

        $result = $this->processor(
            [$this->assignment('assignment-ok', 'Working source')],
            new BatchProcessorGuard([
                'assignment-ok' => new ScheduledAssignmentProcessingResult(
                    found: 1,
                    alreadySeen: 0,
                    queued: 1,
                    sent: 1,
                    failed: 0,
                    httpStatusCodes: [200 => 1],
                    stage: 'raw_article_send',
                ),
            ]),
            $statusPath,
            $heartbeatSender,
            new BatchProcessorFailureSender(),
        )->process(limitPerAssignment: 1);

        self::assertFalse($result->hasErrors());
        self::assertSame(2, $heartbeatSender->attempts);
        self::assertSame(1, $this->readStatus($statusPath)['processedAssignments']);
    }

    /**
     * @param list<ParserAssignment> $assignments
     */
    private function processor(
        array $assignments,
        AssignmentProcessorGuardInterface $guard,
        string $statusPath,
        MainApiHeartbeatSenderInterface $heartbeatSender,
        MainApiParserFailureSenderInterface $failureSender,
    ): AssignmentsBatchProcessor {
        $scheduleStore = new InMemoryAssignmentScheduleStore();

        return new AssignmentsBatchProcessor(
            new BatchProcessorAssignmentsProvider($assignments),
            $guard,
            new ParserRunStatusWriter($statusPath),
            new AssignmentScheduleDecider($scheduleStore),
            $scheduleStore,
            new ParserRunStatusHeartbeatPayloadFactory(),
            $heartbeatSender,
            $failureSender,
        );
    }

    private function assignment(string $assignmentId, string $sourceDisplayName): ParserAssignment
    {
        return new ParserAssignment(
            assignmentId: $assignmentId,
            sourceId: 'source-id',
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

final readonly class BatchProcessorAssignmentsProvider implements MainApiAssignmentsProviderInterface
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

final class BatchProcessorGuard implements AssignmentProcessorGuardInterface
{
    /**
     * @var list<string>
     */
    public array $processedAssignmentIds = [];

    /**
     * @param array<string, ScheduledAssignmentProcessingResult|\Throwable> $results
     */
    public function __construct(
        private readonly array $results,
    ) {
    }

    public function process(
        ParserAssignment $assignment,
        AssignmentScheduleDecision $scheduleDecision,
        int $limit,
    ): ScheduledAssignmentProcessingResult {
        $this->processedAssignmentIds[] = $assignment->assignmentId;
        $result = $this->results[$assignment->assignmentId] ?? null;

        if ($result instanceof \Throwable) {
            throw $result;
        }

        if ($result instanceof ScheduledAssignmentProcessingResult) {
            return $result;
        }

        throw new \RuntimeException('Missing test assignment result.');
    }
}

final class BatchProcessorHeartbeatSender implements MainApiHeartbeatSenderInterface
{
    /**
     * @var list<array{checkedAt: string, status: string, message: string, metrics: array<string, mixed>}>
     */
    public array $payloads = [];

    public int $attempts = 0;

    public function __construct(
        private readonly ?\Throwable $exception = null,
    ) {
    }

    public function send(
        \DateTimeImmutable $checkedAt,
        string $status,
        string $message,
        array $metrics,
    ): void {
        ++$this->attempts;

        if ($this->exception !== null) {
            throw $this->exception;
        }

        $this->payloads[] = [
            'checkedAt' => $checkedAt->format(\DateTimeInterface::ATOM),
            'status' => $status,
            'message' => $message,
            'metrics' => $metrics,
        ];
    }
}

final class BatchProcessorFailureSender implements MainApiParserFailureSenderInterface
{
    /**
     * @var list<array{assignmentId: string, stage: string, message: string, context: array<string, mixed>}>
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
        ];
    }
}

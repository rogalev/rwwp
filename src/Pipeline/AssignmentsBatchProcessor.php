<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\MainApi\MainApiAssignmentsProviderInterface;
use App\MainApi\MainApiHeartbeatSenderInterface;
use App\MainApi\MainApiParserFailureSenderInterface;
use App\Schedule\AssignmentScheduleDecider;
use App\State\AssignmentScheduleStoreInterface;
use App\Status\ParserRunStatusHeartbeatPayloadFactory;
use App\Status\ParserRunStatusWriter;

final readonly class AssignmentsBatchProcessor
{
    public function __construct(
        private MainApiAssignmentsProviderInterface $assignmentsProvider,
        private AssignmentProcessorGuardInterface $processorGuard,
        private ParserRunStatusWriter $statusWriter,
        private AssignmentScheduleDecider $scheduleDecider,
        private AssignmentScheduleStoreInterface $scheduleStore,
        private ParserRunStatusHeartbeatPayloadFactory $heartbeatPayloadFactory,
        private MainApiHeartbeatSenderInterface $heartbeatSender,
        private MainApiParserFailureSenderInterface $failureSender,
        private int $progressHeartbeatMinIntervalSeconds = 10,
    ) {
        if ($this->progressHeartbeatMinIntervalSeconds < 0) {
            throw new \InvalidArgumentException('progressHeartbeatMinIntervalSeconds must be greater than or equal to zero.');
        }
    }

    public function process(int $limitPerAssignment): AssignmentsBatchProcessingResult
    {
        $startedAt = microtime(true);

        if ($limitPerAssignment <= 0) {
            $this->writeFailedStatus('limit-per-assignment must be greater than zero.', $startedAt);

            throw new \InvalidArgumentException('limit-per-assignment must be greater than zero.');
        }

        try {
            $assignments = $this->assignmentsProvider->list();
        } catch (\Throwable $exception) {
            $this->writeFailedStatus($exception->getMessage(), $startedAt);

            throw $exception;
        }

        $found = 0;
        $alreadySeen = 0;
        $queued = 0;
        $sent = 0;
        $failed = 0;
        $transportErrors = 0;
        $httpStatusCodes = [];
        $stage = 'listing';
        $assignmentResults = [];
        $assignmentErrors = [];
        $skippedAssignments = 0;
        $processedAssignments = 0;
        $timedOutAssignments = 0;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $lastProgressHeartbeatSentAt = null;
        $lastHeartbeatAt = '';

        foreach ($assignments as $assignment) {
            $assignmentStartedAt = microtime(true);
            $currentAssignmentId = $assignment->assignmentId;
            $currentSource = $assignment->sourceDisplayName;

            try {
                $scheduleDecision = $this->scheduleDecider->decide($assignment, $now);
                if (!$scheduleDecision->hasDueWork()) {
                    ++$skippedAssignments;
                    ++$processedAssignments;
                    $assignmentResults[] = new AssignmentBatchProcessingResult(
                        assignmentId: $assignment->assignmentId,
                        source: $assignment->sourceDisplayName,
                        found: 0,
                        alreadySeen: 0,
                        queued: 0,
                        sent: 0,
                        failed: 0,
                        stage: 'idle',
                        skipped: true,
                        durationMs: $this->durationMs($assignmentStartedAt),
                    );
                    $this->writeAndMaybeSendProgressStatus(
                        startedAt: $startedAt,
                        totalAssignments: count($assignments),
                        processedAssignments: $processedAssignments,
                        timedOutAssignments: $timedOutAssignments,
                        currentAssignmentId: $currentAssignmentId,
                        currentSource: $currentSource,
                        found: $found,
                        alreadySeen: $alreadySeen,
                        queued: $queued,
                        sent: $sent,
                        failed: $failed,
                        skippedAssignments: $skippedAssignments,
                        httpStatusCodes: $httpStatusCodes,
                        transportErrors: $transportErrors,
                        stage: 'idle',
                        assignmentErrors: $assignmentErrors,
                        lastError: $assignmentErrors[0]['error'] ?? '',
                        lastProgressHeartbeatSentAt: $lastProgressHeartbeatSentAt,
                        lastHeartbeatAt: $lastHeartbeatAt,
                    );

                    continue;
                }

                $result = $this->processorGuard->process($assignment, $scheduleDecision, $limitPerAssignment);
                if ($scheduleDecision->listingDue) {
                    $this->scheduleStore->markListingChecked($assignment->assignmentId, $now);
                }
                if ($scheduleDecision->articleFetchDue) {
                    $this->scheduleStore->markArticleFetched($assignment->assignmentId, $now);
                }

                $found += $result->found;
                $alreadySeen += $result->alreadySeen;
                $queued += $result->queued;
                $sent += $result->sent;
                $failed += $result->failed;
                $transportErrors += $result->transportErrors;
                $stage = $result->stage;
                $httpStatusCodes = $this->mergeHttpStatusCodes($httpStatusCodes, $result->httpStatusCodes);
                if ($result->lastError !== '') {
                    $assignmentErrors[] = [
                        'assignmentId' => $assignment->assignmentId,
                        'source' => $assignment->sourceDisplayName,
                        'error' => $result->lastError,
                    ];
                }

                $assignmentResults[] = new AssignmentBatchProcessingResult(
                    assignmentId: $assignment->assignmentId,
                    source: $assignment->sourceDisplayName,
                    found: $result->found,
                    alreadySeen: $result->alreadySeen,
                    queued: $result->queued,
                    sent: $result->sent,
                    failed: $result->failed,
                    httpStatusCodes: $result->httpStatusCodes,
                    transportErrors: $result->transportErrors,
                    stage: $result->stage,
                    error: $result->lastError,
                    durationMs: $this->durationMs($assignmentStartedAt),
                );
                ++$processedAssignments;
            } catch (\Throwable $exception) {
                if ($exception instanceof AssignmentTimeoutException) {
                    ++$timedOutAssignments;
                    ++$failed;
                    $this->sendTimeoutFailure($exception);
                }

                ++$transportErrors;
                $stage = $exception instanceof AssignmentTimeoutException ? $exception->stage : 'listing';
                $assignmentErrors[] = [
                    'assignmentId' => $assignment->assignmentId,
                    'source' => $assignment->sourceDisplayName,
                    'error' => $exception->getMessage(),
                ];
                $assignmentResults[] = new AssignmentBatchProcessingResult(
                    assignmentId: $assignment->assignmentId,
                    source: $assignment->sourceDisplayName,
                    found: 0,
                    alreadySeen: 0,
                    queued: 0,
                    sent: 0,
                    failed: $exception instanceof AssignmentTimeoutException ? 1 : 0,
                    transportErrors: 1,
                    stage: $stage,
                    error: $exception->getMessage(),
                    durationMs: $this->durationMs($assignmentStartedAt),
                );
                ++$processedAssignments;
            }

            $this->writeAndMaybeSendProgressStatus(
                startedAt: $startedAt,
                totalAssignments: count($assignments),
                processedAssignments: $processedAssignments,
                timedOutAssignments: $timedOutAssignments,
                currentAssignmentId: $currentAssignmentId,
                currentSource: $currentSource,
                found: $found,
                alreadySeen: $alreadySeen,
                queued: $queued,
                sent: $sent,
                failed: $failed,
                skippedAssignments: $skippedAssignments,
                httpStatusCodes: $httpStatusCodes,
                transportErrors: $transportErrors,
                stage: $stage,
                assignmentErrors: $assignmentErrors,
                lastError: $assignmentErrors[0]['error'] ?? '',
                lastProgressHeartbeatSentAt: $lastProgressHeartbeatSentAt,
                lastHeartbeatAt: $lastHeartbeatAt,
            );
        }

        ksort($httpStatusCodes);

        $batchResult = new AssignmentsBatchProcessingResult(
            assignments: count($assignments),
            found: $found,
            alreadySeen: $alreadySeen,
            queued: $queued,
            sent: $sent,
            failed: $failed,
            assignmentResults: $assignmentResults,
            assignmentErrors: $assignmentErrors,
            lastError: $assignmentErrors[0]['error'] ?? '',
            skippedAssignments: $skippedAssignments,
            httpStatusCodes: $httpStatusCodes,
            transportErrors: $transportErrors,
            stage: count($assignments) === $skippedAssignments && $assignments !== [] ? 'idle' : $stage,
            processedAssignments: $processedAssignments,
            timedOutAssignments: $timedOutAssignments,
        );

        $this->writeStatus($batchResult, $startedAt);

        return $batchResult;
    }

    private function writeFailedStatus(string $lastError, float $startedAt): void
    {
        $this->statusWriter->write([
            'mode' => 'main_assignments_batch',
            'durationSeconds' => $this->durationSeconds($startedAt),
            'assignments' => 0,
            'totalAssignments' => 0,
            'processedAssignments' => 0,
            'timedOutAssignments' => 0,
            'currentAssignmentId' => '',
            'currentSource' => '',
            'lastHeartbeatAt' => '',
            'found' => 0,
            'alreadySeen' => 0,
            'queued' => 0,
            'sent' => 0,
            'failed' => 0,
            'skippedAssignments' => 0,
            'httpStatusCodes' => [],
            'transportErrors' => 0,
            'stage' => 'listing',
            'assignmentErrors' => [],
            'lastError' => $lastError,
        ]);
    }

    private function writeStatus(AssignmentsBatchProcessingResult $result, float $startedAt): void
    {
        $status = [
            'mode' => 'main_assignments_batch',
            'durationSeconds' => $this->durationSeconds($startedAt),
            'assignments' => $result->assignments,
            'totalAssignments' => $result->assignments,
            'processedAssignments' => $result->processedAssignments,
            'timedOutAssignments' => $result->timedOutAssignments,
            'currentAssignmentId' => '',
            'currentSource' => '',
            'lastHeartbeatAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'found' => $result->found,
            'alreadySeen' => $result->alreadySeen,
            'queued' => $result->queued,
            'sent' => $result->sent,
            'failed' => $result->failed,
            'skippedAssignments' => $result->skippedAssignments,
            'httpStatusCodes' => $result->httpStatusCodes,
            'transportErrors' => $result->transportErrors,
            'stage' => $result->stage,
            'assignmentErrors' => $result->assignmentErrors,
            'lastError' => $result->lastError,
        ];

        $this->statusWriter->write($status);
        $this->sendHeartbeat($status);
    }

    /**
     * @param array<int, int> $httpStatusCodes
     * @param list<array{assignmentId: string, source: string, error: string}> $assignmentErrors
     */
    private function writeAndMaybeSendProgressStatus(
        float $startedAt,
        int $totalAssignments,
        int $processedAssignments,
        int $timedOutAssignments,
        string $currentAssignmentId,
        string $currentSource,
        int $found,
        int $alreadySeen,
        int $queued,
        int $sent,
        int $failed,
        int $skippedAssignments,
        array $httpStatusCodes,
        int $transportErrors,
        string $stage,
        array $assignmentErrors,
        string $lastError,
        ?float &$lastProgressHeartbeatSentAt,
        string &$lastHeartbeatAt,
    ): void {
        ksort($httpStatusCodes);
        $status = [
            'mode' => 'main_assignments_batch',
            'durationSeconds' => $this->durationSeconds($startedAt),
            'assignments' => $totalAssignments,
            'totalAssignments' => $totalAssignments,
            'processedAssignments' => $processedAssignments,
            'timedOutAssignments' => $timedOutAssignments,
            'currentAssignmentId' => $currentAssignmentId,
            'currentSource' => $currentSource,
            'found' => $found,
            'alreadySeen' => $alreadySeen,
            'queued' => $queued,
            'sent' => $sent,
            'failed' => $failed,
            'skippedAssignments' => $skippedAssignments,
            'httpStatusCodes' => $httpStatusCodes,
            'transportErrors' => $transportErrors,
            'stage' => $stage,
            'assignmentErrors' => $assignmentErrors,
            'lastError' => $lastError,
            'lastHeartbeatAt' => $lastHeartbeatAt,
        ];

        $shouldSendHeartbeat = $this->shouldSendProgressHeartbeat($lastProgressHeartbeatSentAt);
        if ($shouldSendHeartbeat) {
            $lastProgressHeartbeatSentAt = microtime(true);
            $lastHeartbeatAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);
            $status['lastHeartbeatAt'] = $lastHeartbeatAt;
        }

        $this->statusWriter->write($status);
        if ($shouldSendHeartbeat) {
            $this->sendHeartbeat($status);
        }
    }

    /**
     * @param array<string, mixed> $status
     */
    private function sendHeartbeat(array $status): void
    {
        try {
            $payload = $this->heartbeatPayloadFactory->create($status);
            $this->heartbeatSender->send(
                checkedAt: $payload->checkedAt,
                status: $payload->status,
                message: $payload->message,
                metrics: $payload->metrics,
            );
        } catch (\Throwable) {
            // Progress heartbeat is best-effort: it must not break the batch.
        }
    }

    private function shouldSendProgressHeartbeat(?float $lastProgressHeartbeatSentAt): bool
    {
        if ($this->progressHeartbeatMinIntervalSeconds === 0) {
            return true;
        }

        if ($lastProgressHeartbeatSentAt === null) {
            return true;
        }

        return microtime(true) - $lastProgressHeartbeatSentAt >= $this->progressHeartbeatMinIntervalSeconds;
    }

    private function sendTimeoutFailure(AssignmentTimeoutException $exception): void
    {
        try {
            $this->failureSender->send(
                assignmentId: $exception->assignmentId,
                stage: $exception->stage,
                message: $exception->getMessage(),
                context: [
                    'assignmentId' => $exception->assignmentId,
                    'source' => $exception->source,
                    'timeoutSeconds' => $exception->timeoutSeconds,
                    'stage' => $exception->stage,
                ],
                occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
        } catch (\Throwable) {
            // Failure reporting is diagnostic and must not stop batch processing.
        }
    }

    private function durationSeconds(float $startedAt): int
    {
        return max(0, (int) round(microtime(true) - $startedAt));
    }

    private function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    /**
     * @param array<int, int> $left
     * @param array<int, int> $right
     *
     * @return array<int, int>
     */
    private function mergeHttpStatusCodes(array $left, array $right): array
    {
        foreach ($right as $statusCode => $count) {
            $left[$statusCode] = ($left[$statusCode] ?? 0) + $count;
        }

        return $left;
    }
}

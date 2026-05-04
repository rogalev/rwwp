<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\MainApi\MainApiAssignmentsProviderInterface;
use App\Schedule\AssignmentScheduleDecider;
use App\State\AssignmentScheduleStoreInterface;
use App\Status\ParserRunStatusWriter;

final readonly class AssignmentsBatchProcessor
{
    public function __construct(
        private MainApiAssignmentsProviderInterface $assignmentsProvider,
        private AssignmentRawArticleProcessor $processor,
        private ParserRunStatusWriter $statusWriter,
        private AssignmentScheduleDecider $scheduleDecider,
        private AssignmentScheduleStoreInterface $scheduleStore,
    ) {
    }

    public function process(int $limitPerAssignment): AssignmentsBatchProcessingResult
    {
        if ($limitPerAssignment <= 0) {
            $this->writeFailedStatus('limit-per-assignment must be greater than zero.');

            throw new \InvalidArgumentException('limit-per-assignment must be greater than zero.');
        }

        try {
            $assignments = $this->assignmentsProvider->list();
        } catch (\Throwable $exception) {
            $this->writeFailedStatus($exception->getMessage());

            throw $exception;
        }

        $found = 0;
        $alreadySeen = 0;
        $sent = 0;
        $failed = 0;
        $transportErrors = 0;
        $httpStatusCodes = [];
        $stage = 'listing';
        $assignmentResults = [];
        $assignmentErrors = [];
        $skippedAssignments = 0;
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        foreach ($assignments as $assignment) {
            try {
                $scheduleDecision = $this->scheduleDecider->decide($assignment, $now);
                if (!$scheduleDecision->hasDueWork()) {
                    ++$skippedAssignments;
                    $assignmentResults[] = new AssignmentBatchProcessingResult(
                        assignmentId: $assignment->assignmentId,
                        source: $assignment->sourceDisplayName,
                        found: 0,
                        alreadySeen: 0,
                        sent: 0,
                        failed: 0,
                        skipped: true,
                    );

                    continue;
                }

                $result = $this->processor->process($assignment, $limitPerAssignment);
                $this->scheduleStore->markListingChecked($assignment->assignmentId, $now);
                $this->scheduleStore->markArticleFetched($assignment->assignmentId, $now);
                $found += $result->found;
                $alreadySeen += $result->alreadySeen;
                $sent += $result->sent;
                $failed += $result->failed;
                $transportErrors += $result->transportErrors;
                $stage = $result->stage;
                $httpStatusCodes = $this->mergeHttpStatusCodes($httpStatusCodes, $result->httpStatusCodes);
                $assignmentResults[] = new AssignmentBatchProcessingResult(
                    assignmentId: $assignment->assignmentId,
                    source: $assignment->sourceDisplayName,
                    found: $result->found,
                    alreadySeen: $result->alreadySeen,
                    sent: $result->sent,
                    failed: $result->failed,
                    httpStatusCodes: $result->httpStatusCodes,
                    transportErrors: $result->transportErrors,
                );
            } catch (\Throwable $exception) {
                ++$transportErrors;
                $stage = 'listing';
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
                    sent: 0,
                    failed: 0,
                    transportErrors: 1,
                    error: $exception->getMessage(),
                );
            }
        }

        ksort($httpStatusCodes);

        $batchResult = new AssignmentsBatchProcessingResult(
            assignments: count($assignments),
            found: $found,
            alreadySeen: $alreadySeen,
            sent: $sent,
            failed: $failed,
            assignmentResults: $assignmentResults,
            assignmentErrors: $assignmentErrors,
            lastError: $assignmentErrors[0]['error'] ?? '',
            skippedAssignments: $skippedAssignments,
            httpStatusCodes: $httpStatusCodes,
            transportErrors: $transportErrors,
            stage: count($assignments) === $skippedAssignments && $assignments !== [] ? 'idle' : $stage,
        );

        $this->writeStatus($batchResult);

        return $batchResult;
    }

    private function writeFailedStatus(string $lastError): void
    {
        $this->statusWriter->write([
            'mode' => 'main_assignments_batch',
            'assignments' => 0,
            'found' => 0,
            'alreadySeen' => 0,
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

    private function writeStatus(AssignmentsBatchProcessingResult $result): void
    {
        $this->statusWriter->write([
            'mode' => 'main_assignments_batch',
            'assignments' => $result->assignments,
            'found' => $result->found,
            'alreadySeen' => $result->alreadySeen,
            'sent' => $result->sent,
            'failed' => $result->failed,
            'skippedAssignments' => $result->skippedAssignments,
            'httpStatusCodes' => $result->httpStatusCodes,
            'transportErrors' => $result->transportErrors,
            'stage' => $result->stage,
            'assignmentErrors' => $result->assignmentErrors,
            'lastError' => $result->lastError,
        ]);
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

<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\MainApi\MainApiAssignmentsProviderInterface;
use App\Status\ParserRunStatusWriter;

final readonly class AssignmentsBatchProcessor
{
    public function __construct(
        private MainApiAssignmentsProviderInterface $assignmentsProvider,
        private AssignmentRawArticleProcessor $processor,
        private ParserRunStatusWriter $statusWriter,
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
        $assignmentResults = [];
        $assignmentErrors = [];

        foreach ($assignments as $assignment) {
            try {
                $result = $this->processor->process($assignment, $limitPerAssignment);
                $found += $result->found;
                $alreadySeen += $result->alreadySeen;
                $sent += $result->sent;
                $failed += $result->failed;
                $assignmentResults[] = new AssignmentBatchProcessingResult(
                    assignmentId: $assignment->assignmentId,
                    source: $assignment->sourceDisplayName,
                    found: $result->found,
                    alreadySeen: $result->alreadySeen,
                    sent: $result->sent,
                    failed: $result->failed,
                );
            } catch (\Throwable $exception) {
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
                    error: $exception->getMessage(),
                );
            }
        }

        $batchResult = new AssignmentsBatchProcessingResult(
            assignments: count($assignments),
            found: $found,
            alreadySeen: $alreadySeen,
            sent: $sent,
            failed: $failed,
            assignmentResults: $assignmentResults,
            assignmentErrors: $assignmentErrors,
            lastError: $assignmentErrors[0]['error'] ?? '',
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
            'assignmentErrors' => $result->assignmentErrors,
            'lastError' => $result->lastError,
        ]);
    }
}

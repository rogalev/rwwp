<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\MainApi\ParserAssignment;
use App\Schedule\AssignmentScheduleDecision;

final readonly class ScheduledAssignmentProcessor
{
    public function __construct(
        private AssignmentListingEnqueueProcessor $listingProcessor,
        private AssignmentArticleFetchProcessor $articleFetchProcessor,
    ) {
    }

    public function process(
        ParserAssignment $assignment,
        AssignmentScheduleDecision $scheduleDecision,
        int $limit,
    ): ScheduledAssignmentProcessingResult {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('limit must be greater than zero.');
        }

        $listingResult = null;
        $articleFetchResult = null;

        if ($scheduleDecision->listingDue) {
            $listingResult = $this->listingProcessor->process($assignment, $limit);
        }

        if ($scheduleDecision->articleFetchDue) {
            $articleFetchResult = $this->articleFetchProcessor->process($assignment, $limit);
        }

        return new ScheduledAssignmentProcessingResult(
            found: $listingResult?->found ?? 0,
            alreadySeen: $listingResult?->alreadySeen ?? 0,
            queued: $listingResult?->queued ?? 0,
            sent: $articleFetchResult?->sent ?? 0,
            failed: ($listingResult?->failed ?? 0) + ($articleFetchResult?->failed ?? 0),
            httpStatusCodes: $articleFetchResult?->httpStatusCodes ?? [],
            transportErrors: ($listingResult?->transportErrors ?? 0) + ($articleFetchResult?->transportErrors ?? 0),
            stage: $this->stage($scheduleDecision, $listingResult, $articleFetchResult),
            lastError: $listingResult?->lastError ?: ($articleFetchResult?->lastError ?? ''),
        );
    }

    private function stage(
        AssignmentScheduleDecision $scheduleDecision,
        ?AssignmentListingEnqueueResult $listingResult,
        ?AssignmentArticleFetchResult $articleFetchResult,
    ): string {
        if ($articleFetchResult !== null && ($articleFetchResult->sent > 0 || $articleFetchResult->failed > 0)) {
            return $articleFetchResult->stage;
        }

        if ($listingResult !== null && ($listingResult->queued > 0 || $listingResult->failed > 0)) {
            return $listingResult->stage;
        }

        if ($scheduleDecision->articleFetchDue) {
            return 'article_fetch';
        }

        if ($scheduleDecision->listingDue) {
            return 'listing';
        }

        return 'idle';
    }
}

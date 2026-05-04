<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Diagnostics\DiagnosticLoggerInterface;
use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\MainApi\MainApiParserFailureSenderInterface;
use App\MainApi\ParserAssignment;
use App\State\PendingArticleQueueInterface;
use App\State\SeenArticleStoreInterface;

final readonly class AssignmentListingEnqueueProcessor
{
    public function __construct(
        private ArticleListingProviderRegistry $listingProviderRegistry,
        private SeenArticleStoreInterface $seenArticleStore,
        private PendingArticleQueueInterface $pendingArticleQueue,
        private MainApiParserFailureSenderInterface $failureSender,
        private DiagnosticLoggerInterface $diagnostics,
    ) {
    }

    public function process(ParserAssignment $assignment, int $limit): AssignmentListingEnqueueResult
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('limit must be greater than zero.');
        }

        $source = $this->listingSource($assignment);
        $provider = $this->listingProviderRegistry->providerFor($source);
        $found = 0;
        $alreadySeen = 0;
        $queued = 0;
        $this->diagnostics->log('listing.start', [
            'assignmentId' => $assignment->assignmentId,
            'source' => $assignment->sourceDisplayName,
            'listingMode' => $assignment->listingMode,
            'listingUrl' => $assignment->listingUrl,
            'limit' => $limit,
        ]);

        try {
            foreach ($provider->fetchArticleRefs($source) as $articleRef) {
                ++$found;

                if ($this->seenArticleStore->has($articleRef->externalUrl)) {
                    ++$alreadySeen;
                    continue;
                }

                if ($queued >= $limit) {
                    break;
                }

                if ($this->pendingArticleQueue->enqueue($assignment->assignmentId, $articleRef->externalUrl, $articleRef->sourceCode)) {
                    $this->diagnostics->log('listing.article_queued', [
                        'assignmentId' => $assignment->assignmentId,
                        'externalUrl' => $articleRef->externalUrl,
                    ]);
                    ++$queued;
                }
            }
        } catch (\Throwable $exception) {
            $this->diagnostics->log('listing.error', [
                'assignmentId' => $assignment->assignmentId,
                'message' => $exception->getMessage(),
                'exceptionClass' => $exception::class,
            ]);
            $this->sendFailure($assignment, $exception);

            return new AssignmentListingEnqueueResult(
                found: $found,
                alreadySeen: $alreadySeen,
                queued: $queued,
                failed: 1,
                transportErrors: 1,
                lastError: $exception->getMessage(),
            );
        }

        $this->diagnostics->log('listing.done', [
            'assignmentId' => $assignment->assignmentId,
            'found' => $found,
            'alreadySeen' => $alreadySeen,
            'queued' => $queued,
        ]);

        return new AssignmentListingEnqueueResult(
            found: $found,
            alreadySeen: $alreadySeen,
            queued: $queued,
            failed: 0,
        );
    }

    private function listingSource(ParserAssignment $assignment): ListingSource
    {
        return new ListingSource(
            type: $this->listingSourceType($assignment->listingMode),
            sourceCode: $assignment->sourceId,
            categoryCode: $assignment->assignmentId,
            url: $assignment->listingUrl,
        );
    }

    private function listingSourceType(string $listingMode): ListingSourceType
    {
        return match ($listingMode) {
            'rss', ListingSourceType::RssFeed->value => ListingSourceType::RssFeed,
            'html', ListingSourceType::HtmlSection->value => ListingSourceType::HtmlSection,
            default => throw new \InvalidArgumentException(sprintf('Unsupported listing mode "%s".', $listingMode)),
        };
    }

    private function sendFailure(ParserAssignment $assignment, \Throwable $exception): void
    {
        try {
            $this->failureSender->send(
                assignmentId: $assignment->assignmentId,
                stage: 'listing',
                message: $exception->getMessage(),
                context: [
                    'listingUrl' => $assignment->listingUrl,
                    'exceptionClass' => $exception::class,
                ],
                occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
        } catch (\Throwable) {
            // Failure reporting is diagnostic and must not stop local status calculation.
        }
    }
}

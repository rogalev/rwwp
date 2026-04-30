<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Http\DocumentFetcherInterface;
use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\MainApi\MainApiRawArticleSenderInterface;
use App\MainApi\ParserAssignment;
use App\State\SeenArticleStoreInterface;

final readonly class AssignmentRawArticleProcessor
{
    public function __construct(
        private ArticleListingProviderRegistry $listingProviderRegistry,
        private DocumentFetcherInterface $documentFetcher,
        private MainApiRawArticleSenderInterface $rawArticleSender,
        private SeenArticleStoreInterface $seenArticleStore,
    ) {
    }

    public function process(ParserAssignment $assignment, int $limit): AssignmentRawArticleProcessingResult
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('limit must be greater than zero.');
        }

        $source = $this->listingSource($assignment);
        $provider = $this->listingProviderRegistry->providerFor($source);
        $found = 0;
        $alreadySeen = 0;
        $sent = 0;
        $failed = 0;
        $attempted = 0;

        foreach ($provider->fetchArticleRefs($source) as $articleRef) {
            ++$found;

            if ($this->seenArticleStore->has($articleRef->externalUrl)) {
                ++$alreadySeen;
                continue;
            }

            if ($attempted >= $limit) {
                break;
            }

            ++$attempted;
            $this->seenArticleStore->markSeen(
                $articleRef->externalUrl,
                $articleRef->sourceCode,
                $articleRef->categoryCode,
            );

            try {
                $document = $this->documentFetcher->fetch($articleRef->externalUrl);
                $this->rawArticleSender->send(
                    $assignment->assignmentId,
                    $articleRef->externalUrl,
                    $document->content,
                    $document->statusCode,
                    $document->fetchedAt,
                );
                $this->seenArticleStore->markParsed($articleRef->externalUrl);
                ++$sent;
            } catch (\Throwable $exception) {
                $this->seenArticleStore->markFailed($articleRef->externalUrl, $exception->getMessage());
                ++$failed;
            }
        }

        return new AssignmentRawArticleProcessingResult($found, $alreadySeen, $sent, $failed);
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
}

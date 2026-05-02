<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Http\DocumentFetcherInterface;
use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\MainApi\MainApiParserFailureSenderInterface;
use App\MainApi\MainApiRawArticleSenderInterface;
use App\MainApi\ParserAssignment;
use App\State\SeenArticleStoreInterface;

final readonly class AssignmentRawArticleProcessor
{
    private const ALLOWED_HTTP_HEADERS = [
        'accept' => 'Accept',
        'accept-language' => 'Accept-Language',
        'referer' => 'Referer',
    ];

    public function __construct(
        private ArticleListingProviderRegistry $listingProviderRegistry,
        private DocumentFetcherInterface $documentFetcher,
        private MainApiRawArticleSenderInterface $rawArticleSender,
        private MainApiParserFailureSenderInterface $failureSender,
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
        $httpStatusCodes = [];
        $transportErrors = 0;
        $httpHeaders = $this->httpHeaders($assignment);

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

            $stage = 'article_fetch';

            try {
                $document = $this->documentFetcher->fetch($articleRef->externalUrl, $httpHeaders);
                $httpStatusCodes[$document->statusCode] = ($httpStatusCodes[$document->statusCode] ?? 0) + 1;
                $stage = 'raw_article_send';
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
                $this->sendFailure($assignment, $stage, $articleRef->externalUrl, $exception);
                ++$failed;
                ++$transportErrors;
            }
        }

        ksort($httpStatusCodes);

        return new AssignmentRawArticleProcessingResult(
            found: $found,
            alreadySeen: $alreadySeen,
            sent: $sent,
            failed: $failed,
            httpStatusCodes: $httpStatusCodes,
            transportErrors: $transportErrors,
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

    /**
     * @return array<string, string>
     */
    private function httpHeaders(ParserAssignment $assignment): array
    {
        $headers = $assignment->config['httpHeaders'] ?? null;
        if (!\is_array($headers)) {
            return [];
        }

        $safeHeaders = [];
        foreach ($headers as $name => $value) {
            if (!\is_string($name) || !\is_string($value) || trim($value) === '') {
                continue;
            }

            $normalizedName = strtolower(trim($name));
            if (!isset(self::ALLOWED_HTTP_HEADERS[$normalizedName])) {
                continue;
            }

            $safeHeaders[self::ALLOWED_HTTP_HEADERS[$normalizedName]] = trim($value);
        }

        return $safeHeaders;
    }

    private function sendFailure(
        ParserAssignment $assignment,
        string $stage,
        string $externalUrl,
        \Throwable $exception,
    ): void {
        try {
            $this->failureSender->send(
                assignmentId: $assignment->assignmentId,
                stage: $stage,
                message: $exception->getMessage(),
                context: [
                    'externalUrl' => $externalUrl,
                    'exceptionClass' => $exception::class,
                ],
                occurredAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );
        } catch (\Throwable) {
            // Failure reporting is diagnostic and must not stop assignment processing.
        }
    }
}

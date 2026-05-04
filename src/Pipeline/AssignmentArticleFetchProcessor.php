<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Http\DocumentFetcherInterface;
use App\MainApi\MainApiParserFailureSenderInterface;
use App\MainApi\MainApiRawArticleSenderInterface;
use App\MainApi\ParserAssignment;
use App\State\PendingArticleQueueInterface;
use App\State\SeenArticleStoreInterface;

final readonly class AssignmentArticleFetchProcessor
{
    private const ALLOWED_HTTP_HEADERS = [
        'accept' => 'Accept',
        'accept-language' => 'Accept-Language',
        'referer' => 'Referer',
    ];

    public function __construct(
        private PendingArticleQueueInterface $pendingArticleQueue,
        private DocumentFetcherInterface $documentFetcher,
        private MainApiRawArticleSenderInterface $rawArticleSender,
        private MainApiParserFailureSenderInterface $failureSender,
        private SeenArticleStoreInterface $seenArticleStore,
    ) {
    }

    public function process(ParserAssignment $assignment, int $limit): AssignmentArticleFetchResult
    {
        if ($limit <= 0) {
            throw new \InvalidArgumentException('limit must be greater than zero.');
        }

        $sent = 0;
        $failed = 0;
        $transportErrors = 0;
        $httpStatusCodes = [];
        $lastStage = 'article_fetch';
        $lastError = '';
        $httpHeaders = $this->httpHeaders($assignment);

        foreach ($this->pendingArticleQueue->takePending($assignment->assignmentId, $limit) as $pendingArticle) {
            $stage = 'article_fetch';
            $lastStage = $stage;

            try {
                $document = $this->documentFetcher->fetch($pendingArticle->externalUrl, $httpHeaders);
                $httpStatusCodes[$document->statusCode] = ($httpStatusCodes[$document->statusCode] ?? 0) + 1;
                $stage = 'raw_article_send';
                $lastStage = $stage;
                $this->rawArticleSender->send(
                    $assignment->assignmentId,
                    $pendingArticle->externalUrl,
                    $document->content,
                    $document->statusCode,
                    $document->fetchedAt,
                );
                $this->pendingArticleQueue->markSent($assignment->assignmentId, $pendingArticle->externalUrl);
                $this->seenArticleStore->markParsed($pendingArticle->externalUrl);
                ++$sent;
            } catch (\Throwable $exception) {
                $this->pendingArticleQueue->markFailed($assignment->assignmentId, $pendingArticle->externalUrl, $exception->getMessage());
                $this->seenArticleStore->markFailed($pendingArticle->externalUrl, $exception->getMessage());
                $this->sendFailure($assignment, $stage, $pendingArticle->externalUrl, $exception);
                $lastError = $exception->getMessage();
                ++$failed;
                ++$transportErrors;
            }
        }

        ksort($httpStatusCodes);

        return new AssignmentArticleFetchResult(
            sent: $sent,
            failed: $failed,
            httpStatusCodes: $httpStatusCodes,
            transportErrors: $transportErrors,
            stage: $lastStage,
            lastError: $lastError,
        );
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

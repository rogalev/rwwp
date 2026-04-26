<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Article\ArticleParserRegistry;
use App\Listing\ExternalArticleRef;
use App\Output\ParsedArticleSinkInterface;
use App\State\SeenArticleStoreInterface;
use Psr\Log\LoggerInterface;

final readonly class ArticleRefProcessor
{
    public function __construct(
        private ArticleParserRegistry $articleParserRegistry,
        private ParsedArticleSinkInterface $parsedArticleSink,
        private SeenArticleStoreInterface $seenArticleStore,
        private LoggerInterface $logger,
    ) {
    }

    public function process(ExternalArticleRef $articleRef): ArticleProcessingResult
    {
        if ($this->seenArticleStore->has($articleRef->externalUrl)) {
            $this->logger->info('article.already_seen', $this->logContext($articleRef, ArticleProcessingStatus::AlreadySeen));

            return new ArticleProcessingResult(
                status: ArticleProcessingStatus::AlreadySeen,
                externalUrl: $articleRef->externalUrl,
            );
        }

        $this->seenArticleStore->markSeen(
            $articleRef->externalUrl,
            $articleRef->sourceCode,
            $articleRef->categoryCode,
        );

        try {
            $parser = $this->articleParserRegistry->parserFor($articleRef);
        } catch (\RuntimeException $exception) {
            $this->seenArticleStore->markFailed($articleRef->externalUrl, $exception->getMessage());
            $this->logger->warning('article.unsupported', $this->logContext(
                $articleRef,
                ArticleProcessingStatus::SkippedUnsupported,
                error: $exception->getMessage(),
            ));

            return new ArticleProcessingResult(
                status: ArticleProcessingStatus::SkippedUnsupported,
                externalUrl: $articleRef->externalUrl,
                error: $exception->getMessage(),
            );
        }

        try {
            $article = $parser->parse($articleRef);
            $this->parsedArticleSink->write($article);
            $this->seenArticleStore->markParsed($articleRef->externalUrl);
            $this->logger->info('article.parsed', $this->logContext(
                $articleRef,
                ArticleProcessingStatus::Parsed,
                title: $article->title,
                contentLength: $article->contentLength(),
            ));

            return new ArticleProcessingResult(
                status: ArticleProcessingStatus::Parsed,
                externalUrl: $articleRef->externalUrl,
                title: $article->title,
                contentLength: $article->contentLength(),
            );
        } catch (\Throwable $exception) {
            $this->seenArticleStore->markFailed($articleRef->externalUrl, $exception->getMessage());
            $this->logger->error('article.failed', $this->logContext(
                $articleRef,
                ArticleProcessingStatus::Failed,
                error: $exception->getMessage(),
            ));

            return new ArticleProcessingResult(
                status: ArticleProcessingStatus::Failed,
                externalUrl: $articleRef->externalUrl,
                error: $exception->getMessage(),
            );
        }
    }

    /**
     * @return array<string, string|int|null>
     */
    private function logContext(
        ExternalArticleRef $articleRef,
        ArticleProcessingStatus $status,
        ?string $title = null,
        ?int $contentLength = null,
        ?string $error = null,
    ): array {
        return [
            'externalUrl' => $articleRef->externalUrl,
            'sourceCode' => $articleRef->sourceCode,
            'categoryCode' => $articleRef->categoryCode,
            'status' => $status->value,
            'title' => $title,
            'contentLength' => $contentLength,
            'error' => $error,
        ];
    }
}

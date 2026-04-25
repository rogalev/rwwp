<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\Article\ArticleParserRegistry;
use App\Listing\ExternalArticleRef;
use App\State\SeenArticleStoreInterface;

final readonly class ArticleRefProcessor
{
    public function __construct(
        private ArticleParserRegistry $articleParserRegistry,
        private SeenArticleStoreInterface $seenArticleStore,
    ) {
    }

    public function process(ExternalArticleRef $articleRef): ArticleProcessingResult
    {
        try {
            $parser = $this->articleParserRegistry->parserFor($articleRef);
        } catch (\RuntimeException $exception) {
            $this->seenArticleStore->markFailed($articleRef->externalUrl, $exception->getMessage());

            return new ArticleProcessingResult(
                status: ArticleProcessingStatus::SkippedUnsupported,
                externalUrl: $articleRef->externalUrl,
                error: $exception->getMessage(),
            );
        }

        try {
            $article = $parser->parse($articleRef);
            $this->seenArticleStore->markParsed($articleRef->externalUrl);

            return new ArticleProcessingResult(
                status: ArticleProcessingStatus::Parsed,
                externalUrl: $articleRef->externalUrl,
                title: $article->title,
                contentLength: $article->contentLength(),
            );
        } catch (\Throwable $exception) {
            $this->seenArticleStore->markFailed($articleRef->externalUrl, $exception->getMessage());

            return new ArticleProcessingResult(
                status: ArticleProcessingStatus::Failed,
                externalUrl: $articleRef->externalUrl,
                error: $exception->getMessage(),
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Article;

use App\Listing\ExternalArticleRef;

final readonly class ArticleParserRegistry
{
    /**
     * @param iterable<ArticleParserInterface> $parsers
     */
    public function __construct(
        private iterable $parsers,
    ) {
    }

    public function parserFor(ExternalArticleRef $ref): ArticleParserInterface
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($ref)) {
                return $parser;
            }
        }

        throw new \RuntimeException(sprintf('No article parser supports "%s".', $ref->externalUrl));
    }
}

<?php

declare(strict_types=1);

namespace App\Article;

use App\Http\DocumentFetcherInterface;
use App\Listing\ExternalArticleRef;
use Symfony\Component\DomCrawler\Crawler;

final readonly class BbcArticleParser implements ArticleParserInterface
{
    public function __construct(
        private DocumentFetcherInterface $documentFetcher,
    ) {
    }

    public function supports(ExternalArticleRef $ref): bool
    {
        return $ref->sourceCode === 'bbc' && str_contains($ref->externalUrl, 'bbc.com/news/articles/');
    }

    public function parse(ExternalArticleRef $ref): ParsedArticle
    {
        if (!$this->supports($ref)) {
            throw new \InvalidArgumentException(sprintf('BBC article parser does not support "%s".', $ref->externalUrl));
        }

        $document = $this->documentFetcher->fetch($ref->externalUrl);
        $crawler = new Crawler($document->content, $ref->externalUrl);
        $title = $this->requiredText($crawler, 'h1', $ref->externalUrl, 'title');
        $content = $this->articleContent($crawler, $ref->externalUrl);

        return new ParsedArticle(
            externalUrl: $ref->externalUrl,
            sourceCode: $ref->sourceCode,
            categoryCode: $ref->categoryCode,
            title: $title,
            content: $content,
            publishedAt: $this->publishedAt($crawler),
            author: $this->author($crawler),
            metadata: [
                'parser' => self::class,
                'contentType' => $document->contentType,
                'fetchedAt' => $document->fetchedAt->format(\DateTimeInterface::ATOM),
                'userAgent' => $document->userAgent,
            ],
        );
    }

    private function articleContent(Crawler $crawler, string $url): string
    {
        $paragraphs = [];

        $crawler->filter('main p')->each(static function (Crawler $paragraph) use (&$paragraphs): void {
            $text = trim($paragraph->text(''));

            if ($text !== '') {
                $paragraphs[] = $text;
            }
        });

        $content = implode("\n\n", array_values(array_unique($paragraphs)));

        if ($content === '') {
            throw ArticleParseException::missingRequiredField($url, 'content');
        }

        return $content;
    }

    private function requiredText(Crawler $crawler, string $selector, string $url, string $field): string
    {
        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            throw ArticleParseException::missingRequiredField($url, $field);
        }

        $text = trim($node->first()->text(''));

        if ($text === '') {
            throw ArticleParseException::missingRequiredField($url, $field);
        }

        return $text;
    }

    private function publishedAt(Crawler $crawler): ?\DateTimeImmutable
    {
        $time = $crawler->filter('time[datetime]');

        if ($time->count() === 0) {
            return null;
        }

        $datetime = $time->first()->attr('datetime');

        if ($datetime === null || trim($datetime) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($datetime);
        } catch (\Exception) {
            return null;
        }
    }

    private function author(Crawler $crawler): ?string
    {
        $authorSelectors = [
            '[data-testid="byline-new-contributors"]',
            '[data-testid="byline"]',
            'span[class*="byline"]',
        ];

        foreach ($authorSelectors as $selector) {
            $node = $crawler->filter($selector);

            if ($node->count() === 0) {
                continue;
            }

            $author = trim($node->first()->text(''));

            if ($author !== '') {
                return $author;
            }
        }

        return null;
    }
}

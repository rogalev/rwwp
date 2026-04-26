<?php

declare(strict_types=1);

namespace App\Listing;

use App\Http\DocumentFetcherInterface;
use App\Url\UrlNormalizerInterface;
use Symfony\Component\DomCrawler\Crawler;

final readonly class HtmlArticleListingProvider implements ArticleListingProviderInterface
{
    /**
     * @param array<string, string> $linkSelectors
     */
    public function __construct(
        private DocumentFetcherInterface $documentFetcher,
        private UrlNormalizerInterface $urlNormalizer,
        private array $linkSelectors,
    ) {
    }

    public function supports(ListingSource $source): bool
    {
        return $source->type === ListingSourceType::HtmlSection;
    }

    public function fetchArticleRefs(ListingSource $source): iterable
    {
        if (!$this->supports($source)) {
            throw new \InvalidArgumentException(sprintf('Unsupported listing source type "%s".', $source->type->value));
        }

        $selector = $this->selectorFor($source);
        $document = $this->documentFetcher->fetch($source->url);
        $crawler = new Crawler($document->content, $source->url);
        $seenUrls = [];

        foreach ($crawler->filter($selector) as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $href = trim($node->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }

            $externalUrl = $this->urlNormalizer->normalize($this->resolveUrl($href, $source->url));

            if ($externalUrl === '' || isset($seenUrls[$externalUrl])) {
                continue;
            }

            $seenUrls[$externalUrl] = true;

            yield new ExternalArticleRef(
                externalUrl: $externalUrl,
                sourceCode: $source->sourceCode,
                categoryCode: $source->categoryCode,
                listingSourceType: $source->type,
            );
        }
    }

    private function selectorFor(ListingSource $source): string
    {
        $key = $source->sourceCode.'.'.$source->categoryCode.'.'.$source->type->value;
        $selector = $this->linkSelectors[$key] ?? null;

        if ($selector === null || trim($selector) === '') {
            throw new \RuntimeException(sprintf('HTML listing selector is not configured for "%s".', $key));
        }

        return $selector;
    }

    private function resolveUrl(string $href, string $baseUrl): string
    {
        if (preg_match('~^https?://~i', $href) === 1) {
            return $href;
        }

        $base = parse_url($baseUrl);

        if ($base === false || !isset($base['scheme'], $base['host'])) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            return $base['scheme'].':'.$href;
        }

        $origin = $base['scheme'].'://'.$base['host'].(isset($base['port']) ? ':'.$base['port'] : '');

        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $basePath = $base['path'] ?? '/';
        $directory = rtrim(str_ends_with($basePath, '/') ? $basePath : dirname($basePath), '/');

        return $origin.($directory === '' ? '' : $directory).'/'.$href;
    }
}

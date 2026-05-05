<?php

declare(strict_types=1);

namespace App\Listing;

use App\Http\DocumentFetcherInterface;
use App\Url\UrlNormalizerInterface;
use Symfony\Component\DomCrawler\Crawler;

final class HtmlArticleListingProvider implements ArticleListingProviderInterface, HtmlListingSelectorStatsProviderInterface
{
    private ?HtmlListingSelectorStats $lastSelectorStats = null;

    public function __construct(
        private DocumentFetcherInterface $documentFetcher,
        private UrlNormalizerInterface $urlNormalizer,
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
        $nodes = $crawler->filter($selector);
        $matchedNodes = $nodes->count();
        $seenUrls = [];
        $refs = [];

        foreach ($nodes as $node) {
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

            $refs[] = new ExternalArticleRef(
                externalUrl: $externalUrl,
                sourceCode: $source->sourceCode,
                categoryCode: $source->categoryCode,
                listingSourceType: $source->type,
            );
        }

        $this->lastSelectorStats = new HtmlListingSelectorStats(
            selector: $selector,
            matchedNodes: $matchedNodes,
            uniqueUrls: \count($seenUrls),
        );

        if ($matchedNodes === 0 || $refs === []) {
            throw new \RuntimeException(sprintf(
                'HTML listing selector matched 0 links: "%s".',
                $selector,
            ));
        }

        yield from $refs;
    }

    public function lastSelectorStats(): ?HtmlListingSelectorStats
    {
        return $this->lastSelectorStats;
    }

    private function selectorFor(ListingSource $source): string
    {
        $listingConfig = $source->config['listing'] ?? null;
        $selector = \is_array($listingConfig) ? ($listingConfig['linkSelector'] ?? null) : null;

        if (!\is_string($selector) || trim($selector) === '') {
            throw new \RuntimeException('HTML listing selector is not configured in assignment config field "listing.linkSelector".');
        }

        return trim($selector);
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

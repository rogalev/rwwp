<?php

declare(strict_types=1);

namespace App\Listing;

use App\Http\DocumentFetcherInterface;
use App\Url\UrlNormalizerInterface;

final readonly class RssArticleListingProvider implements ArticleListingProviderInterface
{
    public function __construct(
        private DocumentFetcherInterface $documentFetcher,
        private UrlNormalizerInterface $urlNormalizer,
    ) {
    }

    public function supports(ListingSource $source): bool
    {
        return $source->type === ListingSourceType::RssFeed;
    }

    public function fetchArticleRefs(ListingSource $source): iterable
    {
        if (!$this->supports($source)) {
            throw new \InvalidArgumentException(sprintf('Unsupported listing source type "%s".', $source->type->value));
        }

        $document = $this->documentFetcher->fetch($source->url);
        $rss = $this->parseXml($document->content, $source->url);
        $seenUrls = [];

        foreach ($rss->channel->item ?? [] as $item) {
            $externalUrl = $this->urlNormalizer->normalize(trim((string) $item->link));

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

    private function parseXml(string $content, string $url): \SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $xml = simplexml_load_string($content);

            if (!$xml instanceof \SimpleXMLElement) {
                $errors = array_map(
                    static fn (\LibXMLError $error): string => trim($error->message),
                    libxml_get_errors(),
                );

                throw new \RuntimeException(sprintf(
                    'Unable to parse RSS XML from "%s"%s',
                    $url,
                    $errors === [] ? '.' : ': '.implode('; ', $errors),
                ));
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}

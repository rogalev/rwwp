<?php

declare(strict_types=1);

namespace App\Url;

final readonly class TrackingQueryUrlNormalizer implements UrlNormalizerInterface
{
    private const TRACKING_PARAMETERS = [
        'at_campaign',
        'at_medium',
        'fbclid',
        'gclid',
        'utm_campaign',
        'utm_content',
        'utm_medium',
        'utm_source',
        'utm_term',
    ];

    public function normalize(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['query'])) {
            return $url;
        }

        parse_str($parts['query'], $query);

        foreach (self::TRACKING_PARAMETERS as $parameter) {
            unset($query[$parameter]);
        }

        return $this->buildUrl($parts, http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /**
     * @param array{scheme?: string, host?: string, port?: int, user?: string, pass?: string, path?: string, fragment?: string} $parts
     */
    private function buildUrl(array $parts, string $query): string
    {
        $url = '';

        if (isset($parts['scheme'])) {
            $url .= $parts['scheme'].'://';
        }

        if (isset($parts['user'])) {
            $url .= $parts['user'];

            if (isset($parts['pass'])) {
                $url .= ':'.$parts['pass'];
            }

            $url .= '@';
        }

        if (isset($parts['host'])) {
            $url .= $parts['host'];
        }

        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        $url .= $parts['path'] ?? '';

        if ($query !== '') {
            $url .= '?'.$query;
        }

        if (isset($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }

        return $url;
    }
}

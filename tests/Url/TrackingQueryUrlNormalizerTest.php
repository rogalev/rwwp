<?php

declare(strict_types=1);

namespace App\Tests\Url;

use App\Url\TrackingQueryUrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrackingQueryUrlNormalizerTest extends TestCase
{
    #[DataProvider('normalizeProvider')]
    public function testNormalize(string $url, string $expected): void
    {
        self::assertSame($expected, (new TrackingQueryUrlNormalizer())->normalize($url));
    }

    /**
     * @return iterable<string, array{url: string, expected: string}>
     */
    public static function normalizeProvider(): iterable
    {
        yield 'removes known tracking parameters' => [
            'url' => 'https://example.com/news?id=42&utm_source=rss&utm_medium=email&fbclid=abc&at_campaign=rss',
            'expected' => 'https://example.com/news?id=42',
        ];

        yield 'keeps non-tracking query parameters' => [
            'url' => 'https://example.com/news?category=world&page=2&utm_campaign=feed',
            'expected' => 'https://example.com/news?category=world&page=2',
        ];

        yield 'keeps fragment after removing tracking parameters' => [
            'url' => 'https://example.com/news?utm_content=headline&id=42#comments',
            'expected' => 'https://example.com/news?id=42#comments',
        ];

        yield 'leaves url without query unchanged' => [
            'url' => 'https://example.com/news/42',
            'expected' => 'https://example.com/news/42',
        ];

        yield 'removes query marker when only tracking parameters exist' => [
            'url' => 'https://example.com/news?utm_source=rss&gclid=abc',
            'expected' => 'https://example.com/news',
        ];
    }
}

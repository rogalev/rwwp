<?php

declare(strict_types=1);

namespace App\Tests\Listing;

use App\Listing\ExternalArticleRef;
use App\Listing\ListingSourceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalArticleRefTest extends TestCase
{
    public function testConstructorKeepsValidValues(): void
    {
        $ref = new ExternalArticleRef(
            'https://example.com/news/42',
            'bbc',
            'world',
            ListingSourceType::RssFeed,
        );

        self::assertSame('https://example.com/news/42', $ref->externalUrl);
        self::assertSame('bbc', $ref->sourceKey);
        self::assertSame('world', $ref->scopeKey);
        self::assertSame(ListingSourceType::RssFeed, $ref->listingSourceType);
    }

    #[DataProvider('invalidRequiredStringProvider')]
    public function testConstructorRejectsEmptyRequiredStrings(string $field, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->ref(...[$field => '']);
    }

    /**
     * @return iterable<string, array{field: string, expectedMessage: string}>
     */
    public static function invalidRequiredStringProvider(): iterable
    {
        yield 'external url' => [
            'field' => 'externalUrl',
            'expectedMessage' => 'External article URL must not be empty.',
        ];

        yield 'source key' => [
            'field' => 'sourceKey',
            'expectedMessage' => 'External article source key must not be empty.',
        ];

        yield 'scope key' => [
            'field' => 'scopeKey',
            'expectedMessage' => 'External article scope key must not be empty.',
        ];
    }

    private function ref(
        string $externalUrl = 'https://example.com/news/42',
        string $sourceKey = 'bbc',
        string $scopeKey = 'world',
    ): ExternalArticleRef {
        return new ExternalArticleRef(
            $externalUrl,
            $sourceKey,
            $scopeKey,
            ListingSourceType::RssFeed,
        );
    }
}

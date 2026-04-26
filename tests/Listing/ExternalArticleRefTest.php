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
        self::assertSame('bbc', $ref->sourceCode);
        self::assertSame('world', $ref->categoryCode);
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

        yield 'source code' => [
            'field' => 'sourceCode',
            'expectedMessage' => 'External article source code must not be empty.',
        ];

        yield 'category code' => [
            'field' => 'categoryCode',
            'expectedMessage' => 'External article category code must not be empty.',
        ];
    }

    private function ref(
        string $externalUrl = 'https://example.com/news/42',
        string $sourceCode = 'bbc',
        string $categoryCode = 'world',
    ): ExternalArticleRef {
        return new ExternalArticleRef(
            $externalUrl,
            $sourceCode,
            $categoryCode,
            ListingSourceType::RssFeed,
        );
    }
}

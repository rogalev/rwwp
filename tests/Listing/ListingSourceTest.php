<?php

declare(strict_types=1);

namespace App\Tests\Listing;

use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListingSourceTest extends TestCase
{
    public function testConstructorKeepsValidValues(): void
    {
        $source = new ListingSource(
            ListingSourceType::RssFeed,
            'bbc',
            'world',
            'https://example.com/rss.xml',
        );

        self::assertSame(ListingSourceType::RssFeed, $source->type);
        self::assertSame('bbc', $source->sourceCode);
        self::assertSame('world', $source->categoryCode);
        self::assertSame('https://example.com/rss.xml', $source->url);
    }

    #[DataProvider('invalidRequiredStringProvider')]
    public function testConstructorRejectsEmptyRequiredStrings(string $field, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->source(...[$field => '']);
    }

    /**
     * @return iterable<string, array{field: string, expectedMessage: string}>
     */
    public static function invalidRequiredStringProvider(): iterable
    {
        yield 'source code' => [
            'field' => 'sourceCode',
            'expectedMessage' => 'Listing source code must not be empty.',
        ];

        yield 'category code' => [
            'field' => 'categoryCode',
            'expectedMessage' => 'Listing category code must not be empty.',
        ];

        yield 'url' => [
            'field' => 'url',
            'expectedMessage' => 'Listing URL must not be empty.',
        ];
    }

    private function source(
        string $sourceCode = 'bbc',
        string $categoryCode = 'world',
        string $url = 'https://example.com/rss.xml',
    ): ListingSource {
        return new ListingSource(
            ListingSourceType::RssFeed,
            $sourceCode,
            $categoryCode,
            $url,
        );
    }
}

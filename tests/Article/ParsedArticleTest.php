<?php

declare(strict_types=1);

namespace App\Tests\Article;

use App\Article\ParsedArticle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParsedArticleTest extends TestCase
{
    public function testContentLengthReturnsContentByteLength(): void
    {
        $article = $this->article(content: 'Example content.');

        self::assertSame(16, $article->contentLength());
    }

    #[DataProvider('invalidRequiredStringProvider')]
    public function testConstructorRejectsEmptyRequiredStrings(string $field, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->article(...[$field => '']);
    }

    /**
     * @return iterable<string, array{field: string, expectedMessage: string}>
     */
    public static function invalidRequiredStringProvider(): iterable
    {
        yield 'external url' => [
            'field' => 'externalUrl',
            'expectedMessage' => 'Parsed article external URL must not be empty.',
        ];

        yield 'source code' => [
            'field' => 'sourceCode',
            'expectedMessage' => 'Parsed article source code must not be empty.',
        ];

        yield 'category code' => [
            'field' => 'categoryCode',
            'expectedMessage' => 'Parsed article category code must not be empty.',
        ];

        yield 'title' => [
            'field' => 'title',
            'expectedMessage' => 'Parsed article title must not be empty.',
        ];

        yield 'content' => [
            'field' => 'content',
            'expectedMessage' => 'Parsed article content must not be empty.',
        ];
    }

    private function article(
        string $externalUrl = 'https://example.com/news/42',
        string $sourceCode = 'bbc',
        string $categoryCode = 'world',
        string $title = 'Example title',
        string $content = 'Example content.',
    ): ParsedArticle {
        return new ParsedArticle(
            $externalUrl,
            $sourceCode,
            $categoryCode,
            $title,
            $content,
            null,
            null,
        );
    }
}

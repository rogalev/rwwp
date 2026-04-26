<?php

declare(strict_types=1);

namespace App\Tests\Pipeline;

use App\Article\ArticleParserInterface;
use App\Article\ArticleParserRegistry;
use App\Article\ParsedArticle;
use App\Listing\ExternalArticleRef;
use App\Listing\ListingSourceType;
use App\Output\ParsedArticleSinkInterface;
use App\Pipeline\ArticleProcessingStatus;
use App\Pipeline\ArticleRefProcessor;
use App\State\SeenArticleStoreInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ArticleRefProcessorTest extends TestCase
{
    public function testProcessSkipsAlreadySeenArticle(): void
    {
        $state = new FakeSeenArticleStore(alreadySeen: true);
        $parser = new FakeArticleParser();
        $sink = new FakeParsedArticleSink();
        $logger = new InMemoryLogger();
        $processor = $this->processor($state, $parser, $sink, $logger);

        $result = $processor->process($this->articleRef());

        self::assertSame(ArticleProcessingStatus::AlreadySeen, $result->status);
        self::assertSame('https://example.com/news/42', $result->externalUrl);
        self::assertSame(0, $parser->parseCalls);
        self::assertCount(0, $sink->writtenArticles);
        self::assertSame([], $state->seenMarks);
        self::assertSame([], $state->parsedMarks);
        self::assertSame([], $state->failedMarks);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('article.already_seen', $logger->records[0]['message']);
        self::assertSame('ALREADY_SEEN', $logger->records[0]['context']['status']);
    }

    public function testProcessParsesNewArticleAndMarksItParsed(): void
    {
        $state = new FakeSeenArticleStore(alreadySeen: false);
        $parser = new FakeArticleParser();
        $sink = new FakeParsedArticleSink();
        $logger = new InMemoryLogger();
        $processor = $this->processor($state, $parser, $sink, $logger);

        $result = $processor->process($this->articleRef());

        self::assertSame(ArticleProcessingStatus::Parsed, $result->status);
        self::assertSame('https://example.com/news/42', $result->externalUrl);
        self::assertSame('Example title', $result->title);
        self::assertSame(16, $result->contentLength);
        self::assertSame(1, $parser->parseCalls);
        self::assertCount(1, $sink->writtenArticles);
        self::assertSame([
            [
                'externalUrl' => 'https://example.com/news/42',
                'sourceCode' => 'bbc',
                'categoryCode' => 'world',
            ],
        ], $state->seenMarks);
        self::assertSame(['https://example.com/news/42'], $state->parsedMarks);
        self::assertSame([], $state->failedMarks);
        self::assertSame('info', $logger->records[0]['level']);
        self::assertSame('article.parsed', $logger->records[0]['message']);
        self::assertSame('PARSED', $logger->records[0]['context']['status']);
        self::assertSame('Example title', $logger->records[0]['context']['title']);
        self::assertSame(16, $logger->records[0]['context']['contentLength']);
    }

    public function testProcessMarksArticleFailedWhenParserFails(): void
    {
        $state = new FakeSeenArticleStore(alreadySeen: false);
        $parser = new FakeArticleParser(parseException: new \RuntimeException('Parser failed.'));
        $sink = new FakeParsedArticleSink();
        $logger = new InMemoryLogger();
        $processor = $this->processor($state, $parser, $sink, $logger);

        $result = $processor->process($this->articleRef());

        self::assertSame(ArticleProcessingStatus::Failed, $result->status);
        self::assertSame('Parser failed.', $result->error);
        self::assertSame(1, $parser->parseCalls);
        self::assertCount(0, $sink->writtenArticles);
        self::assertSame([
            [
                'externalUrl' => 'https://example.com/news/42',
                'sourceCode' => 'bbc',
                'categoryCode' => 'world',
            ],
        ], $state->seenMarks);
        self::assertSame([], $state->parsedMarks);
        self::assertSame([
            [
                'externalUrl' => 'https://example.com/news/42',
                'error' => 'Parser failed.',
            ],
        ], $state->failedMarks);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame('article.failed', $logger->records[0]['message']);
        self::assertSame('FAILED', $logger->records[0]['context']['status']);
        self::assertSame('Parser failed.', $logger->records[0]['context']['error']);
    }

    public function testProcessMarksArticleUnsupportedWhenNoParserSupportsRef(): void
    {
        $state = new FakeSeenArticleStore(alreadySeen: false);
        $parser = new FakeArticleParser(supports: false);
        $sink = new FakeParsedArticleSink();
        $logger = new InMemoryLogger();
        $processor = $this->processor($state, $parser, $sink, $logger);

        $result = $processor->process($this->articleRef());

        self::assertSame(ArticleProcessingStatus::SkippedUnsupported, $result->status);
        self::assertSame('No article parser supports "https://example.com/news/42".', $result->error);
        self::assertSame(0, $parser->parseCalls);
        self::assertCount(0, $sink->writtenArticles);
        self::assertSame([
            [
                'externalUrl' => 'https://example.com/news/42',
                'sourceCode' => 'bbc',
                'categoryCode' => 'world',
            ],
        ], $state->seenMarks);
        self::assertSame([], $state->parsedMarks);
        self::assertSame([
            [
                'externalUrl' => 'https://example.com/news/42',
                'error' => 'No article parser supports "https://example.com/news/42".',
            ],
        ], $state->failedMarks);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('article.unsupported', $logger->records[0]['message']);
        self::assertSame('SKIPPED_UNSUPPORTED', $logger->records[0]['context']['status']);
    }

    private function processor(
        FakeSeenArticleStore $state,
        FakeArticleParser $parser,
        FakeParsedArticleSink $sink,
        InMemoryLogger $logger,
    ): ArticleRefProcessor {
        return new ArticleRefProcessor(
            new ArticleParserRegistry([$parser]),
            $sink,
            $state,
            $logger,
        );
    }

    private function articleRef(): ExternalArticleRef
    {
        return new ExternalArticleRef(
            'https://example.com/news/42',
            'bbc',
            'world',
            ListingSourceType::RssFeed,
        );
    }
}

final class InMemoryLogger extends AbstractLogger
{
    /**
     * @var list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

final class FakeSeenArticleStore implements SeenArticleStoreInterface
{
    /**
     * @var list<array{externalUrl: string, sourceCode: string, categoryCode: string}>
     */
    public array $seenMarks = [];

    /**
     * @var list<string>
     */
    public array $parsedMarks = [];

    /**
     * @var list<array{externalUrl: string, error: string}>
     */
    public array $failedMarks = [];

    public function __construct(
        private readonly bool $alreadySeen,
    ) {
    }

    public function has(string $externalUrl): bool
    {
        return $this->alreadySeen;
    }

    public function markSeen(string $externalUrl, string $sourceCode, string $categoryCode): void
    {
        $this->seenMarks[] = [
            'externalUrl' => $externalUrl,
            'sourceCode' => $sourceCode,
            'categoryCode' => $categoryCode,
        ];
    }

    public function markParsed(string $externalUrl): void
    {
        $this->parsedMarks[] = $externalUrl;
    }

    public function markFailed(string $externalUrl, string $error): void
    {
        $this->failedMarks[] = [
            'externalUrl' => $externalUrl,
            'error' => $error,
        ];
    }
}

final class FakeArticleParser implements ArticleParserInterface
{
    public int $parseCalls = 0;

    public function __construct(
        private readonly bool $supports = true,
        private readonly ?\Throwable $parseException = null,
    ) {
    }

    public function supports(ExternalArticleRef $ref): bool
    {
        return $this->supports;
    }

    public function parse(ExternalArticleRef $ref): ParsedArticle
    {
        ++$this->parseCalls;

        if ($this->parseException !== null) {
            throw $this->parseException;
        }

        return new ParsedArticle(
            $ref->externalUrl,
            $ref->sourceCode,
            $ref->categoryCode,
            'Example title',
            'Example content.',
            null,
            null,
        );
    }
}

final class FakeParsedArticleSink implements ParsedArticleSinkInterface
{
    /**
     * @var list<ParsedArticle>
     */
    public array $writtenArticles = [];

    public function write(ParsedArticle $article): void
    {
        $this->writtenArticles[] = $article;
    }
}

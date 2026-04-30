<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AssignmentsProcessCommand;
use App\Http\DocumentFetcherInterface;
use App\Http\FetchedDocument;
use App\Listing\ArticleListingProviderInterface;
use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ExternalArticleRef;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\MainApi\MainApiAssignmentsProviderInterface;
use App\MainApi\MainApiRawArticleSenderInterface;
use App\MainApi\ParserAssignment;
use App\MainApi\SendRawArticleResult;
use App\Pipeline\AssignmentRawArticleProcessor;
use App\State\SeenArticleStoreInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AssignmentsProcessCommandTest extends TestCase
{
    public function testProcessesAllAssignments(): void
    {
        $processor = $this->processor();
        $commandTester = new CommandTester(new AssignmentsProcessCommand(
            new AssignmentsProcessAssignmentsProvider([
                $this->assignment('0196a222-2222-7222-8222-222222222222', 'BBC'),
                $this->assignment('0196a333-3333-7333-8333-333333333333', 'CNN'),
            ]),
            $processor,
        ));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '1']);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('0196a222-2222-7222-8222-222222222222', $display);
        self::assertStringContainsString('0196a333-3333-7333-8333-333333333333', $display);
        self::assertStringContainsString('BBC', $display);
        self::assertStringContainsString('CNN', $display);
    }

    public function testShowsEmptyAssignmentsMessage(): void
    {
        $commandTester = new CommandTester(new AssignmentsProcessCommand(
            new AssignmentsProcessAssignmentsProvider([]),
            $this->processor(),
        ));

        $exitCode = $commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Назначения для текущего parser-agent не найдены.', $commandTester->getDisplay());
    }

    public function testFailsOnInvalidLimit(): void
    {
        $commandTester = new CommandTester(new AssignmentsProcessCommand(
            new AssignmentsProcessAssignmentsProvider([]),
            $this->processor(),
        ));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '0']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('limit-per-assignment must be greater than zero.', $commandTester->getDisplay());
    }

    public function testContinuesWhenOneAssignmentFails(): void
    {
        $commandTester = new CommandTester(new AssignmentsProcessCommand(
            new AssignmentsProcessAssignmentsProvider([
                $this->assignment('0196a222-2222-7222-8222-222222222222', 'Broken source'),
                $this->assignment('0196a333-3333-7333-8333-333333333333', 'Working source'),
            ]),
            $this->processor(failingAssignmentId: '0196a222-2222-7222-8222-222222222222'),
        ));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '1']);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Broken source failed.', $display);
        self::assertStringContainsString('Working source', $display);
    }

    private function processor(?string $failingAssignmentId = null): AssignmentRawArticleProcessor
    {
        return new AssignmentRawArticleProcessor(
            new ArticleListingProviderRegistry([new AssignmentsProcessListingProvider($failingAssignmentId)]),
            new AssignmentsProcessDocumentFetcher(),
            new AssignmentsProcessRawArticleSender(),
            new AssignmentsProcessSeenStore(),
        );
    }

    private function assignment(string $assignmentId, string $sourceDisplayName): ParserAssignment
    {
        return new ParserAssignment(
            assignmentId: $assignmentId,
            sourceId: '0196a111-1111-7111-8111-111111111111',
            sourceDisplayName: $sourceDisplayName,
            listingMode: 'rss',
            listingUrl: 'https://feeds.example.com/news/rss.xml',
            articleMode: 'html',
            listingCheckIntervalSeconds: 300,
            articleFetchIntervalSeconds: 10,
            requestTimeoutSeconds: 15,
            config: [],
        );
    }
}

final readonly class AssignmentsProcessAssignmentsProvider implements MainApiAssignmentsProviderInterface
{
    /**
     * @param list<ParserAssignment> $assignments
     */
    public function __construct(
        private array $assignments,
    ) {
    }

    public function list(): array
    {
        return $this->assignments;
    }
}

final readonly class AssignmentsProcessListingProvider implements ArticleListingProviderInterface
{
    public function __construct(
        private ?string $failingAssignmentId = null,
    ) {
    }

    public function supports(ListingSource $source): bool
    {
        return true;
    }

    public function fetchArticleRefs(ListingSource $source): iterable
    {
        if ($source->categoryCode === $this->failingAssignmentId) {
            throw new \RuntimeException('Broken source failed.');
        }

        return [
            new ExternalArticleRef(
                externalUrl: 'https://example.com/news/'.substr($source->categoryCode, 0, 8),
                sourceCode: $source->sourceCode,
                categoryCode: $source->categoryCode,
                listingSourceType: ListingSourceType::RssFeed,
            ),
        ];
    }
}

final readonly class AssignmentsProcessDocumentFetcher implements DocumentFetcherInterface
{
    public function fetch(string $url): FetchedDocument
    {
        return new FetchedDocument(
            url: $url,
            statusCode: 200,
            content: '<html>Article</html>',
            contentType: 'text/html',
            userAgent: 'PHPUnit User-Agent',
            fetchedAt: new \DateTimeImmutable('2026-04-30T10:00:00+00:00'),
        );
    }
}

final readonly class AssignmentsProcessRawArticleSender implements MainApiRawArticleSenderInterface
{
    public function send(
        string $assignmentId,
        string $externalUrl,
        string $rawHtml,
        int $httpStatusCode,
        \DateTimeImmutable $fetchedAt,
    ): SendRawArticleResult {
        return new SendRawArticleResult(
            id: '0196a444-4444-7444-8444-444444444444',
            created: true,
            externalUrl: $externalUrl,
            contentHash: 'content-hash',
        );
    }
}

final class AssignmentsProcessSeenStore implements SeenArticleStoreInterface
{
    public function has(string $externalUrl): bool
    {
        return false;
    }

    public function markSeen(string $externalUrl, string $sourceCode, string $categoryCode): void
    {
    }

    public function markParsed(string $externalUrl): void
    {
    }

    public function markFailed(string $externalUrl, string $error): void
    {
    }
}

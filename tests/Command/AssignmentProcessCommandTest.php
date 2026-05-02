<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\AssignmentProcessCommand;
use App\Http\DocumentFetcherInterface;
use App\Http\FetchedDocument;
use App\Listing\ArticleListingProviderInterface;
use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ExternalArticleRef;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\MainApi\MainApiAssignmentsProviderInterface;
use App\MainApi\MainApiParserFailureSenderInterface;
use App\MainApi\MainApiRawArticleSenderInterface;
use App\MainApi\ParserAssignment;
use App\MainApi\SendRawArticleResult;
use App\Pipeline\AssignmentRawArticleProcessor;
use App\State\SeenArticleStoreInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AssignmentProcessCommandTest extends TestCase
{
    public function testProcessesAssignment(): void
    {
        $commandTester = new CommandTester($this->command([
            $this->assignment('0196a222-2222-7222-8222-222222222222'),
        ]));

        $exitCode = $commandTester->execute([
            'assignmentId' => '0196a222-2222-7222-8222-222222222222',
            '--limit' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Found', $commandTester->getDisplay());
        self::assertStringContainsString('Sent', $commandTester->getDisplay());
        self::assertStringContainsString('1', $commandTester->getDisplay());
    }

    public function testFailsWhenAssignmentNotFound(): void
    {
        $commandTester = new CommandTester($this->command([
            $this->assignment('0196a222-2222-7222-8222-222222222222'),
        ]));

        $exitCode = $commandTester->execute([
            'assignmentId' => '0196a999-9999-7999-8999-999999999999',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Assignment not found: 0196a999-9999-7999-8999-999999999999', $commandTester->getDisplay());
    }

    public function testFailsOnInvalidLimit(): void
    {
        $commandTester = new CommandTester($this->command([
            $this->assignment('0196a222-2222-7222-8222-222222222222'),
        ]));

        $exitCode = $commandTester->execute([
            'assignmentId' => '0196a222-2222-7222-8222-222222222222',
            '--limit' => '0',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('limit must be greater than zero.', $commandTester->getDisplay());
    }

    /**
     * @param list<ParserAssignment> $assignments
     */
    private function command(array $assignments): AssignmentProcessCommand
    {
        return new AssignmentProcessCommand(
            new AssignmentProcessAssignmentsProvider($assignments),
            new AssignmentRawArticleProcessor(
                new ArticleListingProviderRegistry([new AssignmentProcessListingProvider()]),
                new AssignmentProcessDocumentFetcher(),
                new AssignmentProcessRawArticleSender(),
                new AssignmentProcessFailureSender(),
                new AssignmentProcessSeenStore(),
            ),
        );
    }

    private function assignment(string $assignmentId): ParserAssignment
    {
        return new ParserAssignment(
            assignmentId: $assignmentId,
            sourceId: '0196a111-1111-7111-8111-111111111111',
            sourceDisplayName: 'BBC',
            listingMode: 'rss',
            listingUrl: 'https://feeds.bbci.co.uk/news/world/rss.xml',
            articleMode: 'html',
            listingCheckIntervalSeconds: 300,
            articleFetchIntervalSeconds: 10,
            requestTimeoutSeconds: 15,
            config: [],
        );
    }
}

final readonly class AssignmentProcessAssignmentsProvider implements MainApiAssignmentsProviderInterface
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

final readonly class AssignmentProcessListingProvider implements ArticleListingProviderInterface
{
    public function supports(ListingSource $source): bool
    {
        return true;
    }

    public function fetchArticleRefs(ListingSource $source): iterable
    {
        return [
            new ExternalArticleRef(
                externalUrl: 'https://example.com/news/1',
                sourceCode: $source->sourceCode,
                categoryCode: $source->categoryCode,
                listingSourceType: ListingSourceType::RssFeed,
            ),
        ];
    }
}

final readonly class AssignmentProcessDocumentFetcher implements DocumentFetcherInterface
{
    public function fetch(string $url, array $headers = []): FetchedDocument
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

final readonly class AssignmentProcessRawArticleSender implements MainApiRawArticleSenderInterface
{
    public function send(
        string $assignmentId,
        string $externalUrl,
        string $rawHtml,
        int $httpStatusCode,
        \DateTimeImmutable $fetchedAt,
    ): SendRawArticleResult {
        return new SendRawArticleResult(
            id: '0196a333-3333-7333-8333-333333333333',
            created: true,
            externalUrl: $externalUrl,
            contentHash: 'content-hash',
        );
    }
}

final readonly class AssignmentProcessFailureSender implements MainApiParserFailureSenderInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function send(
        string $assignmentId,
        string $stage,
        string $message,
        array $context,
        \DateTimeImmutable $occurredAt,
    ): void {
    }
}

final class AssignmentProcessSeenStore implements SeenArticleStoreInterface
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

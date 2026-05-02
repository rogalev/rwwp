<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RunOnceCommand;
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
use App\Pipeline\AssignmentsBatchProcessor;
use App\State\SeenArticleStoreInterface;
use App\Status\ParserRunStatusWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RunOnceCommandTest extends TestCase
{
    public function testRunsOneBatchCycle(): void
    {
        $statusPath = $this->temporaryStatusPath();
        $commandTester = new CommandTester(new RunOnceCommand($this->batchProcessor(
            [$this->assignment('0196a222-2222-7222-8222-222222222222', 'BBC')],
            $statusPath,
        )));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '1']);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Assignments', $display);
        self::assertStringContainsString('Sent', $display);
        self::assertStringContainsString('1', $display);

        $status = $this->readStatus($statusPath);
        self::assertSame('main_assignments_batch', $status['mode']);
        self::assertSame(1, $status['assignments']);
        self::assertSame(1, $status['sent']);
        self::assertSame([200 => 1], $status['httpStatusCodes']);
        self::assertSame(0, $status['transportErrors']);
        self::assertSame('', $status['lastError']);
    }

    public function testFailsOnInvalidLimit(): void
    {
        $commandTester = new CommandTester(new RunOnceCommand($this->batchProcessor([], $this->temporaryStatusPath())));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '0']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('limit-per-assignment must be greater than zero.', $commandTester->getDisplay());
    }

    public function testReturnsFailureWhenAssignmentFailsAndWritesStatus(): void
    {
        $statusPath = $this->temporaryStatusPath();
        $commandTester = new CommandTester(new RunOnceCommand($this->batchProcessor(
            [$this->assignment('0196a222-2222-7222-8222-222222222222', 'BBC')],
            $statusPath,
            failingAssignmentId: '0196a222-2222-7222-8222-222222222222',
        )));

        $exitCode = $commandTester->execute(['--limit-per-assignment' => '1']);

        self::assertSame(Command::FAILURE, $exitCode);

        $status = $this->readStatus($statusPath);
        self::assertSame('Assignment failed.', $status['lastError']);
        self::assertSame([], $status['httpStatusCodes']);
        self::assertSame(1, $status['transportErrors']);
        self::assertSame([
            [
                'assignmentId' => '0196a222-2222-7222-8222-222222222222',
                'source' => 'BBC',
                'error' => 'Assignment failed.',
            ],
        ], $status['assignmentErrors']);
    }

    /**
     * @param list<ParserAssignment> $assignments
     */
    private function batchProcessor(
        array $assignments,
        string $statusPath,
        ?string $failingAssignmentId = null,
    ): AssignmentsBatchProcessor {
        return new AssignmentsBatchProcessor(
            new RunOnceAssignmentsProvider($assignments),
            new AssignmentRawArticleProcessor(
                new ArticleListingProviderRegistry([new RunOnceListingProvider($failingAssignmentId)]),
                new RunOnceDocumentFetcher(),
                new RunOnceRawArticleSender(),
                new RunOnceSeenStore(),
            ),
            new ParserRunStatusWriter($statusPath),
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

    private function temporaryStatusPath(): string
    {
        return sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/status/parser-run.json';
    }

    /**
     * @return array<string, mixed>
     */
    private function readStatus(string $path): array
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}

final readonly class RunOnceAssignmentsProvider implements MainApiAssignmentsProviderInterface
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

final readonly class RunOnceListingProvider implements ArticleListingProviderInterface
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
            throw new \RuntimeException('Assignment failed.');
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

final readonly class RunOnceDocumentFetcher implements DocumentFetcherInterface
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

final readonly class RunOnceRawArticleSender implements MainApiRawArticleSenderInterface
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

final class RunOnceSeenStore implements SeenArticleStoreInterface
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

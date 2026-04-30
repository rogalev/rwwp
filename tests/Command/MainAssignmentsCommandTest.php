<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MainAssignmentsCommand;
use App\MainApi\MainApiAssignmentsProviderInterface;
use App\MainApi\MainApiRequestFailed;
use App\MainApi\ParserAssignment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MainAssignmentsCommandTest extends TestCase
{
    public function testShowsAssignments(): void
    {
        $commandTester = $this->commandTester([
            new ParserAssignment(
                assignmentId: '0196a222-2222-7222-8222-222222222222',
                sourceId: '0196a111-1111-7111-8111-111111111111',
                sourceDisplayName: 'BBC',
                listingMode: 'rss',
                listingUrl: 'https://feeds.bbci.co.uk/news/world/rss.xml',
                articleMode: 'html',
                listingCheckIntervalSeconds: 300,
                articleFetchIntervalSeconds: 10,
                requestTimeoutSeconds: 15,
                config: [],
            ),
        ]);

        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('0196a222-2222-7222-8222-222222222222', $display);
        self::assertStringContainsString('BBC', $display);
        self::assertStringContainsString('rss', $display);
        self::assertStringContainsString('https://feeds.bbci.co.uk/news/world/rss.xml', $display);
        self::assertStringContainsString('Получено назначений: 1.', $display);
    }

    public function testShowsEmptyAssignmentsMessage(): void
    {
        $commandTester = $this->commandTester([]);

        $exitCode = $commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Назначения для текущего parser-agent не найдены.', $commandTester->getDisplay());
    }

    public function testFailsOnMainApiError(): void
    {
        $commandTester = new CommandTester(new MainAssignmentsCommand(
            new FailingAssignmentsProvider(new MainApiRequestFailed('Main API is unavailable.')),
        ));

        $exitCode = $commandTester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Main API is unavailable.', $commandTester->getDisplay());
    }

    /**
     * @param list<ParserAssignment> $assignments
     */
    private function commandTester(array $assignments): CommandTester
    {
        return new CommandTester(new MainAssignmentsCommand(new FixedAssignmentsProvider($assignments)));
    }
}

final readonly class FixedAssignmentsProvider implements MainApiAssignmentsProviderInterface
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

final readonly class FailingAssignmentsProvider implements MainApiAssignmentsProviderInterface
{
    public function __construct(
        private \Throwable $exception,
    ) {
    }

    public function list(): array
    {
        throw $this->exception;
    }
}

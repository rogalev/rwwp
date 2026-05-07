<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SelfCheckCommand;
use App\MainApi\MainApiAssignmentsProviderInterface;
use App\MainApi\ParserAssignment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SelfCheckCommandTest extends TestCase
{
    public function testSucceedsWhenRequiredRuntimeChecksPass(): void
    {
        $stateDir = sys_get_temp_dir().'/rww-parser-self-check-state-'.bin2hex(random_bytes(4));
        $statusDir = sys_get_temp_dir().'/rww-parser-self-check-status-'.bin2hex(random_bytes(4));
        mkdir($stateDir);
        mkdir($statusDir);

        $tester = new CommandTester(new SelfCheckCommand(
            stateDsn: 'sqlite:'.$stateDir.'/parser.sqlite',
            runStatusPath: $statusDir.'/run-status.json',
            assignmentsProvider: new SelfCheckAssignmentsProvider([]),
            pcntlAvailableForTest: true,
        ));

        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('pcntl_fork доступен', $tester->getDisplay());
        self::assertStringContainsString('назначений: 0', $tester->getDisplay());
    }

    public function testFailsWhenMainApiIsNotAvailable(): void
    {
        $stateDir = sys_get_temp_dir().'/rww-parser-self-check-state-'.bin2hex(random_bytes(4));
        $statusDir = sys_get_temp_dir().'/rww-parser-self-check-status-'.bin2hex(random_bytes(4));
        mkdir($stateDir);
        mkdir($statusDir);

        $tester = new CommandTester(new SelfCheckCommand(
            stateDsn: 'sqlite:'.$stateDir.'/parser.sqlite',
            runStatusPath: $statusDir.'/run-status.json',
            assignmentsProvider: new SelfCheckAssignmentsProvider(exception: new \RuntimeException('main is down')),
            pcntlAvailableForTest: true,
        ));

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('main is down', $tester->getDisplay());
    }

    public function testFailsWhenPcntlIsNotAvailable(): void
    {
        $tester = new CommandTester(new SelfCheckCommand(
            stateDsn: 'sqlite:'.sys_get_temp_dir().'/parser.sqlite',
            runStatusPath: sys_get_temp_dir().'/run-status.json',
            assignmentsProvider: new SelfCheckAssignmentsProvider([]),
            pcntlAvailableForTest: false,
        ));

        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('pcntl_fork недоступен', $tester->getDisplay());
    }
}

final readonly class SelfCheckAssignmentsProvider implements MainApiAssignmentsProviderInterface
{
    /**
     * @param list<ParserAssignment> $assignments
     */
    public function __construct(
        private array $assignments = [],
        private ?\Throwable $exception = null,
    ) {
    }

    public function list(): array
    {
        if ($this->exception !== null) {
            throw $this->exception;
        }

        return $this->assignments;
    }
}

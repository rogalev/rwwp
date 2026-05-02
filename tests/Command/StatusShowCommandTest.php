<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\StatusShowCommand;
use App\Status\ParserRunStatusReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class StatusShowCommandTest extends TestCase
{
    public function testShowsMainAssignmentsBatchStatus(): void
    {
        $commandTester = $this->commandTester([
            'checkedAt' => '2026-04-30T10:00:00+00:00',
            'mode' => 'main_assignments_batch',
            'assignments' => 2,
            'found' => 5,
            'alreadySeen' => 1,
            'sent' => 3,
            'failed' => 1,
            'stage' => 'raw_article_send',
            'httpStatusCodes' => [200 => 3, 403 => 1],
            'transportErrors' => 2,
            'assignmentErrors' => [],
            'lastError' => '',
        ]);

        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Parser status', $display);
        self::assertStringContainsString('main_assignments_batch', $display);
        self::assertStringContainsString('Assignments', $display);
        self::assertStringContainsString('Sent', $display);
        self::assertStringContainsString('Stage', $display);
        self::assertStringContainsString('raw_article_send', $display);
        self::assertStringContainsString('3', $display);
        self::assertStringContainsString('HTTP statuses', $display);
        self::assertStringContainsString('{"200":3,"403":1}', $display);
        self::assertStringContainsString('Transport errors', $display);
        self::assertStringContainsString('2', $display);
    }

    public function testShowsAssignmentErrors(): void
    {
        $commandTester = $this->commandTester([
            'checkedAt' => '2026-04-30T10:00:00+00:00',
            'mode' => 'main_assignments_batch',
            'assignments' => 2,
            'found' => 1,
            'alreadySeen' => 0,
            'sent' => 1,
            'failed' => 0,
            'assignmentErrors' => [
                [
                    'assignmentId' => '0196a222-2222-7222-8222-222222222222',
                    'source' => 'BBC',
                    'error' => 'Listing failed.',
                ],
            ],
            'lastError' => 'Listing failed.',
        ]);

        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Assignment errors', $display);
        self::assertStringContainsString('0196a222-2222-7222-8222-222222222222', $display);
        self::assertStringContainsString('BBC', $display);
        self::assertStringContainsString('Listing failed.', $display);
    }

    public function testShowsLegacyStatus(): void
    {
        $commandTester = $this->commandTester([
            'checkedAt' => '2026-04-30T10:00:00+00:00',
            'sourceCode' => 'bbc',
            'categoryCode' => 'world',
            'listingType' => 'rss_feed',
            'found' => 3,
            'alreadySeen' => 1,
            'processed' => 2,
            'parsed' => 1,
            'failed' => 1,
            'unsupported' => 0,
            'lastError' => 'Parser failed.',
        ]);

        $exitCode = $commandTester->execute([]);
        $display = $commandTester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('bbc', $display);
        self::assertStringContainsString('world', $display);
        self::assertStringContainsString('rss_feed', $display);
        self::assertStringContainsString('Parsed', $display);
        self::assertStringContainsString('Unsupported', $display);
    }

    /**
     * @param array<string, mixed> $status
     */
    private function commandTester(array $status): CommandTester
    {
        $path = sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/status/parser-run.json';
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return new CommandTester(new StatusShowCommand(new ParserRunStatusReader($path)));
    }
}

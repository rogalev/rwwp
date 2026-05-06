<?php

declare(strict_types=1);

namespace App\Command;

use App\Status\ParserRunStatusReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:status:show',
    description: 'Показывает последний статус запуска parser-agent.',
)]
final class StatusShowCommand extends Command
{
    public function __construct(
        private readonly ParserRunStatusReader $statusReader,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $status = $this->statusReader->read();
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Parser status');
        if (($status['mode'] ?? null) === 'main_assignments_batch') {
            $this->showMainAssignmentsBatchStatus($io, $status);

            return Command::SUCCESS;
        }

        $this->showLegacyStatus($io, $status);

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $status
     */
    private function showMainAssignmentsBatchStatus(SymfonyStyle $io, array $status): void
    {
        $io->definitionList(
            ['Checked at' => $this->stringValue($status['checkedAt'] ?? null)],
            ['Mode' => $this->stringValue($status['mode'] ?? null)],
            ['Assignments' => $this->stringValue($status['assignments'] ?? null)],
            ['Processed assignments' => $this->stringValue($status['processedAssignments'] ?? null)],
            ['Timed out assignments' => $this->stringValue($status['timedOutAssignments'] ?? null)],
            ['Current assignment ID' => $this->stringValue($status['currentAssignmentId'] ?? null)],
            ['Current source' => $this->stringValue($status['currentSource'] ?? null)],
            ['Last heartbeat at' => $this->stringValue($status['lastHeartbeatAt'] ?? null)],
            ['Skipped' => $this->stringValue($status['skippedAssignments'] ?? null)],
            ['Found' => $this->stringValue($status['found'] ?? null)],
            ['Already seen' => $this->stringValue($status['alreadySeen'] ?? null)],
            ['Queued' => $this->stringValue($status['queued'] ?? null)],
            ['Sent' => $this->stringValue($status['sent'] ?? null)],
            ['Failed' => $this->stringValue($status['failed'] ?? null)],
            ['Stage' => $this->stringValue($status['stage'] ?? null)],
            ['HTTP statuses' => $this->stringValue($status['httpStatusCodes'] ?? null)],
            ['Transport errors' => $this->stringValue($status['transportErrors'] ?? null)],
            ['Last error' => $this->stringValue($status['lastError'] ?? null)],
        );

        $assignmentErrors = $status['assignmentErrors'] ?? [];
        if (!\is_array($assignmentErrors) || $assignmentErrors === []) {
            return;
        }

        $rows = [];
        foreach ($assignmentErrors as $assignmentError) {
            if (!\is_array($assignmentError)) {
                continue;
            }

            $rows[] = [
                $this->stringValue($assignmentError['assignmentId'] ?? null),
                $this->stringValue($assignmentError['source'] ?? null),
                $this->stringValue($assignmentError['error'] ?? null),
            ];
        }

        if ($rows !== []) {
            $io->section('Assignment errors');
            $io->table(['Assignment ID', 'Source', 'Error'], $rows);
        }
    }

    /**
     * @param array<string, mixed> $status
     */
    private function showLegacyStatus(SymfonyStyle $io, array $status): void
    {
        $io->definitionList(
            ['Checked at' => $this->stringValue($status['checkedAt'] ?? null)],
            ['Source' => $this->stringValue($status['sourceCode'] ?? null)],
            ['Category' => $this->stringValue($status['categoryCode'] ?? null)],
            ['Listing type' => $this->stringValue($status['listingType'] ?? null)],
            ['Found' => $this->stringValue($status['found'] ?? null)],
            ['Already seen' => $this->stringValue($status['alreadySeen'] ?? null)],
            ['Processed' => $this->stringValue($status['processed'] ?? null)],
            ['Parsed' => $this->stringValue($status['parsed'] ?? null)],
            ['Failed' => $this->stringValue($status['failed'] ?? null)],
            ['Unsupported' => $this->stringValue($status['unsupported'] ?? null)],
            ['Last error' => $this->stringValue($status['lastError'] ?? null)],
        );
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\MainApi\MainApiAssignmentsProviderInterface;
use App\Pipeline\AssignmentRawArticleProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:assignments:process',
    description: 'Обрабатывает все назначения из main и отправляет сырой HTML статей обратно в main.',
)]
final class AssignmentsProcessCommand extends Command
{
    public function __construct(
        private readonly MainApiAssignmentsProviderInterface $assignmentsProvider,
        private readonly AssignmentRawArticleProcessor $processor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit-per-assignment',
            null,
            InputOption::VALUE_REQUIRED,
            'Максимальное число новых статей на одно назначение за запуск.',
            '1',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $limit = $this->readLimit($input);
            $assignments = $this->assignmentsProvider->list();
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($assignments === []) {
            $io->info('Назначения для текущего parser-agent не найдены.');

            return Command::SUCCESS;
        }

        $hasErrors = false;
        $rows = [];
        foreach ($assignments as $assignment) {
            try {
                $result = $this->processor->process($assignment, $limit);
                $rows[] = [
                    $assignment->assignmentId,
                    $assignment->sourceDisplayName,
                    (string) $result->found,
                    (string) $result->alreadySeen,
                    (string) $result->sent,
                    (string) $result->failed,
                    '',
                ];
            } catch (\Throwable $exception) {
                $hasErrors = true;
                $rows[] = [
                    $assignment->assignmentId,
                    $assignment->sourceDisplayName,
                    '0',
                    '0',
                    '0',
                    '0',
                    $exception->getMessage(),
                ];
            }
        }

        $io->table(['Assignment ID', 'Source', 'Found', 'Already seen', 'Sent', 'Failed', 'Error'], $rows);

        return $hasErrors ? Command::FAILURE : Command::SUCCESS;
    }

    private function readLimit(InputInterface $input): int
    {
        $limit = filter_var($input->getOption('limit-per-assignment'), FILTER_VALIDATE_INT);
        if (!\is_int($limit) || $limit <= 0) {
            throw new \InvalidArgumentException('limit-per-assignment must be greater than zero.');
        }

        return $limit;
    }
}

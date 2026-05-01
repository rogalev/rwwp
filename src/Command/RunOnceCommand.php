<?php

declare(strict_types=1);

namespace App\Command;

use App\Pipeline\AssignmentsBatchProcessingResult;
use App\Pipeline\AssignmentsBatchProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:run-once',
    description: 'Выполняет один production-цикл parser-agent и завершает процесс.',
)]
final class RunOnceCommand extends Command
{
    public function __construct(
        private readonly AssignmentsBatchProcessor $batchProcessor,
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
            $result = $this->batchProcessor->process($this->readLimit($input));
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Assignments' => (string) $result->assignments],
            ['Found' => (string) $result->found],
            ['Already seen' => (string) $result->alreadySeen],
            ['Sent' => (string) $result->sent],
            ['Failed' => (string) $result->failed],
            ['Last error' => $result->lastError],
        );

        return $result->hasErrors() ? Command::FAILURE : Command::SUCCESS;
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

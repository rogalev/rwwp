<?php

declare(strict_types=1);

namespace App\Command;

use App\MainApi\MainApiAssignmentsProviderInterface;
use App\MainApi\ParserAssignment;
use App\Pipeline\AssignmentRawArticleProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:assignment:process',
    description: 'Обрабатывает одно назначение из main и отправляет сырой HTML статей обратно в main.',
)]
final class AssignmentProcessCommand extends Command
{
    public function __construct(
        private readonly MainApiAssignmentsProviderInterface $assignmentsProvider,
        private readonly AssignmentRawArticleProcessor $processor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('assignmentId', InputArgument::REQUIRED, 'ID назначения из main.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимальное число новых статей за запуск.', '1')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $assignmentId = $this->readAssignmentId($input);
            $limit = $this->readLimit($input);
            $assignment = $this->findAssignment($assignmentId);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $result = $this->processor->process($assignment, $limit);

        $io->definitionList(
            ['Found' => (string) $result->found],
            ['Already seen' => (string) $result->alreadySeen],
            ['Sent' => (string) $result->sent],
            ['Failed' => (string) $result->failed],
        );

        return Command::SUCCESS;
    }

    private function readAssignmentId(InputInterface $input): string
    {
        $assignmentId = trim((string) $input->getArgument('assignmentId'));
        if ($assignmentId === '') {
            throw new \InvalidArgumentException('assignmentId must not be blank.');
        }

        return $assignmentId;
    }

    private function readLimit(InputInterface $input): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (!\is_int($limit) || $limit <= 0) {
            throw new \InvalidArgumentException('limit must be greater than zero.');
        }

        return $limit;
    }

    private function findAssignment(string $assignmentId): ParserAssignment
    {
        foreach ($this->assignmentsProvider->list() as $assignment) {
            if ($assignment->assignmentId === $assignmentId) {
                return $assignment;
            }
        }

        throw new \RuntimeException('Assignment not found: '.$assignmentId);
    }
}

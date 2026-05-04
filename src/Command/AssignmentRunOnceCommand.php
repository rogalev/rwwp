<?php

declare(strict_types=1);

namespace App\Command;

use App\MainApi\MainApiAssignmentsProviderInterface;
use App\MainApi\ParserAssignment;
use App\Pipeline\ScheduledAssignmentProcessor;
use App\Schedule\AssignmentScheduleDecider;
use App\State\AssignmentScheduleStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:assignment:run-once',
    description: 'Обрабатывает одно назначение через актуальный scheduled pipeline parser-agent.',
)]
final class AssignmentRunOnceCommand extends Command
{
    public function __construct(
        private readonly MainApiAssignmentsProviderInterface $assignmentsProvider,
        private readonly AssignmentScheduleDecider $scheduleDecider,
        private readonly AssignmentScheduleStoreInterface $scheduleStore,
        private readonly ScheduledAssignmentProcessor $processor,
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
            $assignment = $this->findAssignment($this->readAssignmentId($input));
            $limit = $this->readLimit($input);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $scheduleDecision = $this->scheduleDecider->decide($assignment, $now);

            if (!$scheduleDecision->hasDueWork()) {
                $this->showSkipped($io, $assignment);

                return Command::SUCCESS;
            }

            $result = $this->processor->process($assignment, $scheduleDecision, $limit);
            if ($scheduleDecision->listingDue) {
                $this->scheduleStore->markListingChecked($assignment->assignmentId, $now);
            }
            if ($scheduleDecision->articleFetchDue) {
                $this->scheduleStore->markArticleFetched($assignment->assignmentId, $now);
            }
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Assignment ID' => $assignment->assignmentId],
            ['Source' => $assignment->sourceDisplayName],
            ['Stage' => $result->stage],
            ['Found' => (string) $result->found],
            ['Already seen' => (string) $result->alreadySeen],
            ['Queued' => (string) $result->queued],
            ['Sent' => (string) $result->sent],
            ['Failed' => (string) $result->failed],
            ['Transport errors' => (string) $result->transportErrors],
            ['Last error' => $result->lastError],
        );

        return $result->lastError === '' ? Command::SUCCESS : Command::FAILURE;
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

    private function showSkipped(SymfonyStyle $io, ParserAssignment $assignment): void
    {
        $io->definitionList(
            ['Assignment ID' => $assignment->assignmentId],
            ['Source' => $assignment->sourceDisplayName],
            ['Stage' => 'idle'],
            ['Skipped' => 'yes'],
        );
    }
}

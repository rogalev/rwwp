<?php

declare(strict_types=1);

namespace App\Command;

use App\MainApi\MainApiAssignmentsProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:main:assignments',
    description: 'Показывает назначения parser-agent, полученные из main.',
)]
final class MainAssignmentsCommand extends Command
{
    public function __construct(
        private readonly MainApiAssignmentsProviderInterface $assignmentsClient,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $assignments = $this->assignmentsClient->list();
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($assignments === []) {
            $io->info('Назначения для текущего parser-agent не найдены.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($assignments as $assignment) {
            $rows[] = [
                $assignment->assignmentId,
                $assignment->sourceDisplayName,
                $assignment->listingMode,
                $assignment->listingUrl,
                $assignment->articleMode,
            ];
        }

        $io->table(['Assignment ID', 'Source', 'Listing mode', 'Listing URL', 'Article mode'], $rows);
        $io->success(sprintf('Получено назначений: %d.', count($assignments)));

        return Command::SUCCESS;
    }
}

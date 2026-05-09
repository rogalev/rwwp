<?php

declare(strict_types=1);

namespace App\Command;

use App\Image\ImageDownloadBatchProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:image-download:run-once',
    description: 'Скачивает изображения по задачам main и отправляет результат обратно.',
)]
final class ImageDownloadRunOnceCommand extends Command
{
    public function __construct(
        private readonly ImageDownloadBatchProcessor $processor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Максимальное число изображений за запуск.', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->processor->process($this->limit($input));

        $io->definitionList(
            ['Tasks' => (string) $result->tasks],
            ['Downloaded' => (string) $result->downloaded],
            ['Failed' => (string) $result->failed],
        );

        return $result->hasErrors() ? Command::FAILURE : Command::SUCCESS;
    }

    private function limit(InputInterface $input): int
    {
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT);
        if (!\is_int($limit) || $limit <= 0) {
            throw new \InvalidArgumentException('limit must be greater than zero.');
        }

        return min(50, $limit);
    }
}

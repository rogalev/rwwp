<?php

declare(strict_types=1);

namespace App\Command;

use App\State\SeenArticleStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:state:mark',
    description: 'Marks an article URL as seen in the local parser state.',
)]
final class ParserStateMarkCommand extends Command
{
    public function __construct(
        private readonly SeenArticleStoreInterface $seenArticleStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'External article URL.')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'Source code.', 'unknown')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Category code.', 'unknown')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = (string) $input->getArgument('url');
        $sourceCode = (string) $input->getOption('source');
        $categoryCode = (string) $input->getOption('category');
        $alreadySeen = $this->seenArticleStore->has($url);

        $this->seenArticleStore->markSeen($url, $sourceCode, $categoryCode);

        if ($alreadySeen) {
            $io->warning(sprintf('URL already exists in parser state: %s', $url));

            return Command::SUCCESS;
        }

        $io->success(sprintf('URL marked as seen: %s', $url));

        return Command::SUCCESS;
    }
}

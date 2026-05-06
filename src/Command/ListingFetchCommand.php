<?php

declare(strict_types=1);

namespace App\Command;

use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:listing:fetch',
    description: 'Fetches article references from a listing source.',
)]
final class ListingFetchCommand extends Command
{
    public function __construct(
        private readonly ArticleListingProviderRegistry $listingProviderRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('type', InputArgument::REQUIRED, 'Listing type: html_section or rss_feed.')
            ->addArgument('source', InputArgument::REQUIRED, 'Source code, e.g. bbc.')
            ->addArgument('category', InputArgument::REQUIRED, 'Category code, e.g. world.')
            ->addArgument('url', InputArgument::REQUIRED, 'Listing URL.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = new ListingSource(
            type: ListingSourceType::from((string) $input->getArgument('type')),
            sourceKey: (string) $input->getArgument('source'),
            scopeKey: (string) $input->getArgument('category'),
            url: (string) $input->getArgument('url'),
        );
        $provider = $this->listingProviderRegistry->providerFor($source);
        $rows = [];

        foreach ($provider->fetchArticleRefs($source) as $articleRef) {
            $rows[] = [
                $articleRef->sourceKey,
                $articleRef->scopeKey,
                $articleRef->listingSourceType->value,
                $articleRef->externalUrl,
            ];
        }

        $io->table(['Source', 'Category', 'Listing type', 'External URL'], $rows);
        $io->success(sprintf('Found %d article reference(s).', count($rows)));

        return Command::SUCCESS;
    }
}

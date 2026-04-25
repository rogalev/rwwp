<?php

declare(strict_types=1);

namespace App\Command;

use App\Listing\ArticleListingProviderInterface;
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
    /**
     * @param iterable<ArticleListingProviderInterface> $listingProviders
     */
    public function __construct(
        private readonly iterable $listingProviders,
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
            sourceCode: (string) $input->getArgument('source'),
            categoryCode: (string) $input->getArgument('category'),
            url: (string) $input->getArgument('url'),
        );
        $provider = $this->providerFor($source);
        $rows = [];

        foreach ($provider->fetchArticleRefs($source) as $articleRef) {
            $rows[] = [
                $articleRef->sourceCode,
                $articleRef->categoryCode,
                $articleRef->listingSourceType->value,
                $articleRef->externalUrl,
            ];
        }

        $io->table(['Source', 'Category', 'Listing type', 'External URL'], $rows);
        $io->success(sprintf('Found %d article reference(s).', count($rows)));

        return Command::SUCCESS;
    }

    private function providerFor(ListingSource $source): ArticleListingProviderInterface
    {
        foreach ($this->listingProviders as $provider) {
            if ($provider->supports($source)) {
                return $provider;
            }
        }

        throw new \RuntimeException(sprintf('No listing provider supports "%s".', $source->type->value));
    }
}

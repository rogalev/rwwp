<?php

declare(strict_types=1);

namespace App\Command;

use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\Pipeline\ArticleRefProcessor;
use App\State\SeenArticleStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:rss:parse-new',
    description: 'Fetches an RSS feed and parses new supported articles.',
)]
final class RssParseNewCommand extends Command
{
    public function __construct(
        private readonly ArticleListingProviderRegistry $listingProviderRegistry,
        private readonly ArticleRefProcessor $articleRefProcessor,
        private readonly SeenArticleStoreInterface $seenArticleStore,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Source code, e.g. bbc.')
            ->addArgument('category', InputArgument::REQUIRED, 'Category code, e.g. world.')
            ->addArgument('url', InputArgument::REQUIRED, 'RSS feed URL.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of new refs to process.', 5)
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = new ListingSource(
            type: ListingSourceType::RssFeed,
            sourceCode: (string) $input->getArgument('source'),
            categoryCode: (string) $input->getArgument('category'),
            url: (string) $input->getArgument('url'),
        );
        $limit = max(1, (int) $input->getOption('limit'));
        $provider = $this->listingProviderRegistry->providerFor($source);
        $rows = [];
        $found = 0;
        $alreadySeen = 0;
        $processed = 0;

        foreach ($provider->fetchArticleRefs($source) as $articleRef) {
            ++$found;

            if ($this->seenArticleStore->has($articleRef->externalUrl)) {
                ++$alreadySeen;
                continue;
            }

            $this->seenArticleStore->markSeen($articleRef->externalUrl, $articleRef->sourceCode, $articleRef->categoryCode);
            $rows[] = $this->articleRefProcessor->process($articleRef)->toTableRow();
            ++$processed;

            if ($processed >= $limit) {
                break;
            }
        }

        $io->table(['Status', 'External URL', 'Title', 'Content length', 'Error'], $rows);
        $io->definitionList(
            ['Found before limit' => (string) $found],
            ['Already seen before limit' => (string) $alreadySeen],
            ['Processed new refs' => (string) $processed],
            ['Limit' => (string) $limit],
        );

        return Command::SUCCESS;
    }
}

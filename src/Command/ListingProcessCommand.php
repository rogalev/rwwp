<?php

declare(strict_types=1);

namespace App\Command;

use App\Listing\ArticleListingProviderInterface;
use App\Listing\ExternalArticleRef;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\State\SeenArticleStoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:listing:process',
    description: 'Fetches a listing source and marks new article URLs in local parser state.',
)]
final class ListingProcessCommand extends Command
{
    /**
     * @param iterable<ArticleListingProviderInterface> $listingProviders
     */
    public function __construct(
        private readonly iterable $listingProviders,
        private readonly SeenArticleStoreInterface $seenArticleStore,
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
        $found = 0;
        $alreadySeen = 0;
        $newRows = [];

        foreach ($provider->fetchArticleRefs($source) as $articleRef) {
            ++$found;

            if ($this->seenArticleStore->has($articleRef->externalUrl)) {
                ++$alreadySeen;
                continue;
            }

            $this->markSeen($articleRef);
            $newRows[] = [
                $articleRef->sourceCode,
                $articleRef->categoryCode,
                $articleRef->listingSourceType->value,
                $articleRef->externalUrl,
            ];
        }

        $io->table(['Source', 'Category', 'Listing type', 'External URL'], $newRows);
        $io->definitionList(
            ['Found' => (string) $found],
            ['New' => (string) count($newRows)],
            ['Already seen' => (string) $alreadySeen],
        );

        return Command::SUCCESS;
    }

    private function markSeen(ExternalArticleRef $articleRef): void
    {
        $this->seenArticleStore->markSeen(
            externalUrl: $articleRef->externalUrl,
            sourceCode: $articleRef->sourceCode,
            categoryCode: $articleRef->categoryCode,
        );
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

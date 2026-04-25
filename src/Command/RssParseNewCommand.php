<?php

declare(strict_types=1);

namespace App\Command;

use App\Article\ArticleParserRegistry;
use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ExternalArticleRef;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
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
        private readonly ArticleParserRegistry $articleParserRegistry,
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
            $rows[] = $this->processArticleRef($articleRef);
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

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function processArticleRef(ExternalArticleRef $articleRef): array
    {
        try {
            $parser = $this->articleParserRegistry->parserFor($articleRef);
        } catch (\RuntimeException $exception) {
            $this->seenArticleStore->markFailed($articleRef->externalUrl, $exception->getMessage());

            return [
                'SKIPPED_UNSUPPORTED',
                $articleRef->externalUrl,
                '',
                '',
                $exception->getMessage(),
            ];
        }

        try {
            $article = $parser->parse($articleRef);
            $this->seenArticleStore->markParsed($articleRef->externalUrl);

            return [
                'PARSED',
                $articleRef->externalUrl,
                $article->title,
                (string) $article->contentLength(),
                '',
            ];
        } catch (\Throwable $exception) {
            $this->seenArticleStore->markFailed($articleRef->externalUrl, $exception->getMessage());

            return [
                'FAILED',
                $articleRef->externalUrl,
                '',
                '',
                $exception->getMessage(),
            ];
        }
    }
}

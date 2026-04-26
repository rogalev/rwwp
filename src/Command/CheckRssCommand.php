<?php

declare(strict_types=1);

namespace App\Command;

use App\Listing\ArticleListingProviderRegistry;
use App\Listing\ListingSource;
use App\Listing\ListingSourceType;
use App\Pipeline\ArticleProcessingStatus;
use App\Pipeline\ArticleRefProcessor;
use App\Status\ParserRunStatusWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:check:rss',
    description: 'Checks that RSS listing and article processing pipeline can run.',
)]
final class CheckRssCommand extends Command
{
    public function __construct(
        private readonly ArticleListingProviderRegistry $listingProviderRegistry,
        private readonly ArticleRefProcessor $articleRefProcessor,
        private readonly ParserRunStatusWriter $statusWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Source code, e.g. bbc.')
            ->addArgument('category', InputArgument::REQUIRED, 'Category code, e.g. world.')
            ->addArgument('url', InputArgument::REQUIRED, 'RSS feed URL.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of new refs to process.', 1)
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
        $rows = [];
        $found = 0;
        $alreadySeen = 0;
        $processed = 0;
        $parsed = 0;
        $failed = 0;
        $unsupported = 0;
        $lastError = null;

        try {
            $provider = $this->listingProviderRegistry->providerFor($source);

            foreach ($provider->fetchArticleRefs($source) as $articleRef) {
                ++$found;

                $result = $this->articleRefProcessor->process($articleRef);

                if ($result->status === ArticleProcessingStatus::AlreadySeen) {
                    ++$alreadySeen;
                    continue;
                }

                $rows[] = $result->toTableRow();
                ++$processed;

                if ($result->status === ArticleProcessingStatus::Parsed) {
                    ++$parsed;
                }

                if ($result->status === ArticleProcessingStatus::Failed) {
                    ++$failed;
                    $lastError = $result->error;
                }

                if ($result->status === ArticleProcessingStatus::SkippedUnsupported) {
                    ++$unsupported;
                    $lastError = $result->error;
                }

                if ($processed >= $limit) {
                    break;
                }
            }
        } catch (\Throwable $exception) {
            $this->writeStatus($source, $found, $alreadySeen, $processed, $parsed, $failed, $unsupported, $exception->getMessage());
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $this->writeStatus($source, $found, $alreadySeen, $processed, $parsed, $failed, $unsupported, $lastError);
        $io->table(['Status', 'External URL', 'Title', 'Content length', 'Error'], $rows);
        $io->definitionList(
            ['Found before limit' => (string) $found],
            ['Already seen before limit' => (string) $alreadySeen],
            ['Processed new refs' => (string) $processed],
            ['Limit' => (string) $limit],
        );

        if ($found === 0) {
            $io->warning('RSS check finished, but no article references were found.');
        } else {
            $io->success('RSS check finished.');
        }

        return Command::SUCCESS;
    }

    private function writeStatus(
        ListingSource $source,
        int $found,
        int $alreadySeen,
        int $processed,
        int $parsed,
        int $failed,
        int $unsupported,
        ?string $lastError,
    ): void {
        $this->statusWriter->write([
            'sourceCode' => $source->sourceCode,
            'categoryCode' => $source->categoryCode,
            'listingType' => $source->type->value,
            'found' => $found,
            'alreadySeen' => $alreadySeen,
            'processed' => $processed,
            'parsed' => $parsed,
            'failed' => $failed,
            'unsupported' => $unsupported,
            'lastError' => $lastError,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Article\ArticleParserInterface;
use App\Listing\ExternalArticleRef;
use App\Listing\ListingSourceType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:article:parse',
    description: 'Parses an external article URL and prints extracted metadata.',
)]
final class ArticleParseCommand extends Command
{
    /**
     * @param iterable<ArticleParserInterface> $articleParsers
     */
    public function __construct(
        private readonly iterable $articleParsers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::REQUIRED, 'Source code, e.g. bbc.')
            ->addArgument('category', InputArgument::REQUIRED, 'Category code, e.g. world.')
            ->addArgument('url', InputArgument::REQUIRED, 'Article URL.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $ref = new ExternalArticleRef(
            externalUrl: (string) $input->getArgument('url'),
            sourceCode: (string) $input->getArgument('source'),
            categoryCode: (string) $input->getArgument('category'),
            listingSourceType: ListingSourceType::RssFeed,
        );
        $article = $this->parserFor($ref)->parse($ref);

        $io->definitionList(
            ['Title' => $article->title],
            ['Published at' => $article->publishedAt?->format(\DateTimeInterface::ATOM) ?? 'unknown'],
            ['Author' => $article->author ?? 'unknown'],
            ['Content length' => (string) $article->contentLength()],
            ['Preview' => mb_substr($article->content, 0, 500)],
        );

        return Command::SUCCESS;
    }

    private function parserFor(ExternalArticleRef $ref): ArticleParserInterface
    {
        foreach ($this->articleParsers as $parser) {
            if ($parser->supports($ref)) {
                return $parser;
            }
        }

        throw new \RuntimeException(sprintf('No article parser supports "%s".', $ref->externalUrl));
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Http\DocumentFetcherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:fetch',
    description: 'Fetches an external document and prints response metadata.',
)]
final class FetchDocumentCommand extends Command
{
    public function __construct(
        private readonly DocumentFetcherInterface $documentFetcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'Document URL.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $document = $this->documentFetcher->fetch((string) $input->getArgument('url'));

        $io->definitionList(
            ['URL' => $document->url],
            ['Status code' => (string) $document->statusCode],
            ['Content type' => $document->contentType ?? 'unknown'],
            ['Content length' => (string) $document->contentLength()],
            ['User-Agent' => $document->userAgent],
            ['Fetched at' => $document->fetchedAt->format(\DateTimeInterface::ATOM)],
        );

        return Command::SUCCESS;
    }
}

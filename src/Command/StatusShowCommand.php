<?php

declare(strict_types=1);

namespace App\Command;

use App\Status\ParserRunStatusReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:status:show',
    description: 'Shows the last parser-agent run status.',
)]
final class StatusShowCommand extends Command
{
    public function __construct(
        private readonly ParserRunStatusReader $statusReader,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $status = $this->statusReader->read();
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->title('Parser status');
        $io->definitionList(
            ['Checked at' => $this->stringValue($status['checkedAt'] ?? null)],
            ['Source' => $this->stringValue($status['sourceCode'] ?? null)],
            ['Category' => $this->stringValue($status['categoryCode'] ?? null)],
            ['Listing type' => $this->stringValue($status['listingType'] ?? null)],
            ['Found' => $this->stringValue($status['found'] ?? null)],
            ['Already seen' => $this->stringValue($status['alreadySeen'] ?? null)],
            ['Processed' => $this->stringValue($status['processed'] ?? null)],
            ['Parsed' => $this->stringValue($status['parsed'] ?? null)],
            ['Failed' => $this->stringValue($status['failed'] ?? null)],
            ['Unsupported' => $this->stringValue($status['unsupported'] ?? null)],
            ['Last error' => $this->stringValue($status['lastError'] ?? null)],
        );

        return Command::SUCCESS;
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

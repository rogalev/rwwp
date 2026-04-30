<?php

declare(strict_types=1);

namespace App\Command;

use App\MainApi\MainApiRawArticleSenderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:main:raw-article:send',
    description: 'Отправляет сырой HTML статьи в main вручную.',
)]
final class MainRawArticleSendCommand extends Command
{
    public function __construct(
        private readonly MainApiRawArticleSenderInterface $rawArticleSender,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('assignmentId', InputArgument::REQUIRED, 'ID назначения из main.')
            ->addArgument('externalUrl', InputArgument::REQUIRED, 'URL статьи во внешнем источнике.')
            ->addArgument('htmlFile', InputArgument::REQUIRED, 'Путь к HTML-файлу статьи.')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'HTTP status, полученный от внешнего источника.', '200')
            ->addOption('fetched-at', null, InputOption::VALUE_REQUIRED, 'Время получения статьи в ISO 8601. По умолчанию текущее UTC-время.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $assignmentId = $this->readStringArgument($input, 'assignmentId');
            $externalUrl = $this->readStringArgument($input, 'externalUrl');
            $rawHtml = $this->readHtmlFile($this->readStringArgument($input, 'htmlFile'));
            $httpStatusCode = $this->readStatusCode($input);
            $fetchedAt = $this->readFetchedAt($input);

            $result = $this->rawArticleSender->send(
                $assignmentId,
                $externalUrl,
                $rawHtml,
                $httpStatusCode,
                $fetchedAt,
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['ID' => $result->id],
            ['Created' => $result->created ? 'yes' : 'no'],
            ['External URL' => $result->externalUrl],
            ['Content hash' => $result->contentHash],
        );

        return Command::SUCCESS;
    }

    private function readStringArgument(InputInterface $input, string $name): string
    {
        $value = (string) $input->getArgument($name);
        if (trim($value) === '') {
            throw new \InvalidArgumentException($name.' must not be blank.');
        }

        return $value;
    }

    private function readHtmlFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException('HTML file is not readable: '.$path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Failed to read HTML file: '.$path);
        }

        return $content;
    }

    private function readStatusCode(InputInterface $input): int
    {
        $status = filter_var($input->getOption('status'), FILTER_VALIDATE_INT);
        if (!\is_int($status) || $status < 100 || $status > 599) {
            throw new \InvalidArgumentException('status must be a valid HTTP status code.');
        }

        return $status;
    }

    private function readFetchedAt(InputInterface $input): \DateTimeImmutable
    {
        $fetchedAt = $input->getOption('fetched-at');
        if ($fetchedAt === null) {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        try {
            return new \DateTimeImmutable((string) $fetchedAt);
        } catch (\Exception) {
            throw new \InvalidArgumentException('fetched-at must be a valid date.');
        }
    }
}

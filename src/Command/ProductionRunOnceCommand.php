<?php

declare(strict_types=1);

namespace App\Command;

use App\MainApi\MainApiHeartbeatSenderInterface;
use App\Image\ImageDownloadBatchProcessorInterface;
use App\MainApi\AssignmentRunStats;
use App\MainApi\MainApiAssignmentRunsSenderInterface;
use App\Pipeline\AssignmentsBatchProcessingResult;
use App\Pipeline\AssignmentsBatchProcessor;
use App\Status\ParserRunStatusHeartbeatPayloadFactory;
use App\Status\ParserRunStatusReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:production:run-once',
    description: 'Выполняет один production-цикл parser-agent и отправляет heartbeat в main.',
)]
final class ProductionRunOnceCommand extends Command
{
    public function __construct(
        private readonly AssignmentsBatchProcessor $batchProcessor,
        private readonly ParserRunStatusReader $statusReader,
        private readonly ParserRunStatusHeartbeatPayloadFactory $payloadFactory,
        private readonly MainApiHeartbeatSenderInterface $heartbeatSender,
        private readonly MainApiAssignmentRunsSenderInterface $assignmentRunsSender,
        private readonly ImageDownloadBatchProcessorInterface $imageDownloadBatchProcessor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'limit-per-assignment',
            null,
            InputOption::VALUE_REQUIRED,
            'Максимальное число новых статей на одно назначение за запуск.',
            '1',
        );
        $this->addOption(
            'image-limit',
            null,
            InputOption::VALUE_REQUIRED,
            'Максимальное число изображений за запуск production-цикла.',
            '10',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = null;
        $hasProductionError = false;

        try {
            $result = $this->batchProcessor->process($this->readLimit($input));
            $this->showResult($io, $result);
            $this->sendAssignmentRuns($result);
            $io->success('Статистика назначений отправлена в main.');
            $imageResult = $this->imageDownloadBatchProcessor->process($this->readImageLimit($input));
            $this->showImageResult($io, $imageResult);
            if ($imageResult->hasErrors()) {
                $hasProductionError = true;
            }
        } catch (\Throwable $exception) {
            $hasProductionError = true;
            $io->error($exception->getMessage());
        }

        try {
            $this->sendHeartbeat();
            $io->success('Heartbeat отправлен в main.');
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return $hasProductionError || $result?->hasErrors() ? Command::FAILURE : Command::SUCCESS;
    }

    private function readLimit(InputInterface $input): int
    {
        $limit = filter_var($input->getOption('limit-per-assignment'), FILTER_VALIDATE_INT);
        if (!\is_int($limit) || $limit <= 0) {
            throw new \InvalidArgumentException('limit-per-assignment must be greater than zero.');
        }

        return $limit;
    }

    private function readImageLimit(InputInterface $input): int
    {
        $limit = filter_var($input->getOption('image-limit'), FILTER_VALIDATE_INT);
        if (!\is_int($limit) || $limit <= 0) {
            throw new \InvalidArgumentException('image-limit must be greater than zero.');
        }

        return min(50, $limit);
    }

    private function showResult(SymfonyStyle $io, AssignmentsBatchProcessingResult $result): void
    {
        $io->definitionList(
            ['Assignments' => (string) $result->assignments],
            ['Found' => (string) $result->found],
            ['Already seen' => (string) $result->alreadySeen],
            ['Queued' => (string) $result->queued],
            ['Sent' => (string) $result->sent],
            ['Failed' => (string) $result->failed],
            ['Last error' => $result->lastError],
        );
    }

    private function showImageResult(SymfonyStyle $io, \App\Image\ImageDownloadBatchResult $result): void
    {
        $io->section('Image downloads');
        $io->definitionList(
            ['Tasks' => (string) $result->tasks],
            ['Downloaded' => (string) $result->downloaded],
            ['Failed' => (string) $result->failed],
        );
    }

    private function sendHeartbeat(): void
    {
        $payload = $this->payloadFactory->create($this->statusReader->read());

        $this->heartbeatSender->send(
            checkedAt: $payload->checkedAt,
            status: $payload->status,
            message: $payload->message,
            metrics: $payload->metrics,
        );
    }

    private function sendAssignmentRuns(AssignmentsBatchProcessingResult $result): void
    {
        $this->assignmentRunsSender->send(
            checkedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            items: array_map(
                fn ($assignmentResult): AssignmentRunStats => new AssignmentRunStats(
                    assignmentId: $assignmentResult->assignmentId,
                    stage: $assignmentResult->stage,
                    status: $this->assignmentRunStatus($assignmentResult->error),
                    found: $assignmentResult->found,
                    queued: $assignmentResult->queued,
                    alreadySeen: $assignmentResult->alreadySeen,
                    sent: $assignmentResult->sent,
                    failed: $assignmentResult->failed,
                    skipped: $assignmentResult->skipped,
                    httpStatusCodes: $assignmentResult->httpStatusCodes,
                    transportErrors: $assignmentResult->transportErrors,
                    durationMs: 0,
                    lastError: $assignmentResult->error,
                ),
                $result->assignmentResults,
            ),
        );
    }

    private function assignmentRunStatus(string $error): string
    {
        return $error === '' ? 'ok' : 'error';
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\MainApi\MainApiHeartbeatSenderInterface;
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
                    durationMs: $assignmentResult->durationMs,
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

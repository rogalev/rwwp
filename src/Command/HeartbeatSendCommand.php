<?php

declare(strict_types=1);

namespace App\Command;

use App\MainApi\MainApiHeartbeatSenderInterface;
use App\Status\ParserRunStatusReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:heartbeat:send',
    description: 'Отправляет текущий локальный статус parser-agent в main heartbeat API.',
)]
final class HeartbeatSendCommand extends Command
{
    public function __construct(
        private readonly ParserRunStatusReader $statusReader,
        private readonly MainApiHeartbeatSenderInterface $heartbeatSender,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $status = $this->statusReader->read();
            $this->heartbeatSender->send(
                checkedAt: $this->checkedAt($status),
                status: $this->status($status),
                message: $this->message($status),
                metrics: $this->metrics($status),
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('Heartbeat отправлен в main.');

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $status
     */
    private function checkedAt(array $status): \DateTimeImmutable
    {
        if (isset($status['checkedAt']) && \is_string($status['checkedAt']) && trim($status['checkedAt']) !== '') {
            return new \DateTimeImmutable($status['checkedAt']);
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $status
     */
    private function status(array $status): string
    {
        return $this->message($status) === '' ? 'ok' : 'error';
    }

    /**
     * @param array<string, mixed> $status
     */
    private function message(array $status): string
    {
        return isset($status['lastError']) && \is_string($status['lastError']) ? $status['lastError'] : '';
    }

    /**
     * @param array<string, mixed> $status
     *
     * @return array<string, mixed>
     */
    private function metrics(array $status): array
    {
        return [
            'durationSeconds' => $this->intValue($status['durationSeconds'] ?? null),
            'foundLinks' => $this->intValue($status['found'] ?? null),
            'acceptedRawArticles' => $this->intValue($status['sent'] ?? null),
            'failedArticles' => $this->intValue($status['failed'] ?? null),
            'httpStatusCodes' => \is_array($status['httpStatusCodes'] ?? null) ? $status['httpStatusCodes'] : [],
            'transportErrors' => $this->intValue($status['transportErrors'] ?? null),
        ];
    }

    private function intValue(mixed $value): int
    {
        return \is_int($value) ? $value : 0;
    }
}

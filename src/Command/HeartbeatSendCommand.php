<?php

declare(strict_types=1);

namespace App\Command;

use App\MainApi\MainApiHeartbeatSenderInterface;
use App\Status\ParserRunStatusHeartbeatPayloadFactory;
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
        private readonly ParserRunStatusHeartbeatPayloadFactory $payloadFactory,
        private readonly MainApiHeartbeatSenderInterface $heartbeatSender,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $status = $this->statusReader->read();
            $payload = $this->payloadFactory->create($status);
            $this->heartbeatSender->send(
                checkedAt: $payload->checkedAt,
                status: $payload->status,
                message: $payload->message,
                metrics: $payload->metrics,
            );
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('Heartbeat отправлен в main.');

        return Command::SUCCESS;
    }
}

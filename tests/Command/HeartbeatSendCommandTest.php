<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\HeartbeatSendCommand;
use App\MainApi\MainApiHeartbeatSenderInterface;
use App\Status\ParserRunStatusHeartbeatPayloadFactory;
use App\Status\ParserRunStatusReader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class HeartbeatSendCommandTest extends TestCase
{
    public function testSendsHeartbeatFromLocalStatus(): void
    {
        $statusPath = $this->writeStatus([
            'checkedAt' => '2026-05-02T10:00:00+00:00',
            'mode' => 'main_assignments_batch',
            'found' => 5,
            'sent' => 3,
            'failed' => 1,
            'httpStatusCodes' => ['200' => 3, '403' => 1],
            'transportErrors' => 2,
            'stage' => 'raw_article_send',
            'lastError' => '',
        ]);
        $sender = new RecordingHeartbeatSender();
        $commandTester = new CommandTester(new HeartbeatSendCommand(
            new ParserRunStatusReader($statusPath),
            new ParserRunStatusHeartbeatPayloadFactory(),
            $sender,
        ));

        $exitCode = $commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('2026-05-02T10:00:00+00:00', $sender->checkedAt?->format(\DateTimeInterface::ATOM));
        self::assertSame('ok', $sender->status);
        self::assertSame('', $sender->message);
        self::assertSame([
            'durationSeconds' => 0,
            'foundLinks' => 5,
            'acceptedRawArticles' => 3,
            'failedArticles' => 1,
            'httpStatusCodes' => ['200' => 3, '403' => 1],
            'transportErrors' => 2,
            'stage' => 'raw_article_send',
        ], $sender->metrics);
        self::assertStringContainsString('Heartbeat отправлен в main.', $commandTester->getDisplay());
    }

    public function testSendsErrorHeartbeatWhenLocalStatusHasLastError(): void
    {
        $statusPath = $this->writeStatus([
            'checkedAt' => '2026-05-02T10:00:00+00:00',
            'found' => 0,
            'sent' => 0,
            'failed' => 0,
            'lastError' => 'Main API unavailable.',
        ]);
        $sender = new RecordingHeartbeatSender();
        $commandTester = new CommandTester(new HeartbeatSendCommand(
            new ParserRunStatusReader($statusPath),
            new ParserRunStatusHeartbeatPayloadFactory(),
            $sender,
        ));

        $exitCode = $commandTester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('error', $sender->status);
        self::assertSame('Main API unavailable.', $sender->message);
    }

    public function testFailsWhenSenderFails(): void
    {
        $statusPath = $this->writeStatus([
            'checkedAt' => '2026-05-02T10:00:00+00:00',
            'lastError' => '',
        ]);
        $commandTester = new CommandTester(new HeartbeatSendCommand(
            new ParserRunStatusReader($statusPath),
            new ParserRunStatusHeartbeatPayloadFactory(),
            new FailingHeartbeatSender(new \RuntimeException('Heartbeat rejected.')),
        ));

        $exitCode = $commandTester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Heartbeat rejected.', $commandTester->getDisplay());
    }

    /**
     * @param array<string, mixed> $status
     */
    private function writeStatus(array $status): string
    {
        $path = sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/status/parser-run.json';
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create test status directory "%s".', $directory));
        }

        file_put_contents($path, json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $path;
    }
}

final class RecordingHeartbeatSender implements MainApiHeartbeatSenderInterface
{
    public ?\DateTimeImmutable $checkedAt = null;
    public ?string $status = null;
    public ?string $message = null;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $metrics = null;

    public function send(\DateTimeImmutable $checkedAt, string $status, string $message, array $metrics): void
    {
        $this->checkedAt = $checkedAt;
        $this->status = $status;
        $this->message = $message;
        $this->metrics = $metrics;
    }
}

final readonly class FailingHeartbeatSender implements MainApiHeartbeatSenderInterface
{
    public function __construct(
        private \Throwable $exception,
    ) {
    }

    public function send(\DateTimeImmutable $checkedAt, string $status, string $message, array $metrics): void
    {
        throw $this->exception;
    }
}

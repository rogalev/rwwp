<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\MainRawArticleSendCommand;
use App\MainApi\MainApiRawArticleSenderInterface;
use App\MainApi\MainApiRequestFailed;
use App\MainApi\SendRawArticleResult;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class MainRawArticleSendCommandTest extends TestCase
{
    public function testSendsRawArticle(): void
    {
        $htmlFile = $this->createHtmlFile('<html>Article</html>');
        $sender = new RecordingRawArticleSender(new SendRawArticleResult(
            jobId: '019e1c71-f428-71fb-a5f3-8928907980cc',
            accepted: true,
            externalUrl: 'https://example.com/news/1',
            status: 'pending',
        ));
        $commandTester = new CommandTester(new MainRawArticleSendCommand($sender));

        $exitCode = $commandTester->execute([
            'assignmentId' => '0196a222-2222-7222-8222-222222222222',
            'externalUrl' => 'https://example.com/news/1',
            'htmlFile' => $htmlFile,
            '--status' => '200',
            '--fetched-at' => '2026-04-30T10:00:00+00:00',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('0196a222-2222-7222-8222-222222222222', $sender->assignmentId);
        self::assertSame('https://example.com/news/1', $sender->externalUrl);
        self::assertSame('<html>Article</html>', $sender->rawHtml);
        self::assertSame(200, $sender->httpStatusCode);
        self::assertSame('2026-04-30T10:00:00+00:00', $sender->fetchedAt?->format(\DateTimeInterface::ATOM));
        self::assertStringContainsString('019e1c71-f428-71fb-a5f3-8928907980cc', $commandTester->getDisplay());
        self::assertStringContainsString('pending', $commandTester->getDisplay());
    }

    public function testFailsWhenHtmlFileIsNotReadable(): void
    {
        $commandTester = new CommandTester(new MainRawArticleSendCommand(new RecordingRawArticleSender(new SendRawArticleResult(
            jobId: 'not-used',
            accepted: false,
            externalUrl: 'not-used',
            status: 'not-used',
        ))));

        $exitCode = $commandTester->execute([
            'assignmentId' => '0196a222-2222-7222-8222-222222222222',
            'externalUrl' => 'https://example.com/news/1',
            'htmlFile' => __DIR__.'/missing.html',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('HTML file is not readable:', $commandTester->getDisplay());
    }

    public function testFailsOnMainApiError(): void
    {
        $htmlFile = $this->createHtmlFile('<html>Article</html>');
        $commandTester = new CommandTester(new MainRawArticleSendCommand(
            new FailingRawArticleSender(new MainApiRequestFailed('Main API rejected raw article.')),
        ));

        $exitCode = $commandTester->execute([
            'assignmentId' => '0196a222-2222-7222-8222-222222222222',
            'externalUrl' => 'https://example.com/news/1',
            'htmlFile' => $htmlFile,
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Main API rejected raw article.', $commandTester->getDisplay());
    }

    private function createHtmlFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'raw-article-');
        self::assertIsString($path);
        file_put_contents($path, $content);

        return $path;
    }
}

final class RecordingRawArticleSender implements MainApiRawArticleSenderInterface
{
    public ?string $assignmentId = null;
    public ?string $externalUrl = null;
    public ?string $rawHtml = null;
    public ?int $httpStatusCode = null;
    public ?\DateTimeImmutable $fetchedAt = null;

    public function __construct(
        private readonly SendRawArticleResult $result,
    ) {
    }

    public function send(
        string $assignmentId,
        string $externalUrl,
        string $rawHtml,
        int $httpStatusCode,
        \DateTimeImmutable $fetchedAt,
    ): SendRawArticleResult {
        $this->assignmentId = $assignmentId;
        $this->externalUrl = $externalUrl;
        $this->rawHtml = $rawHtml;
        $this->httpStatusCode = $httpStatusCode;
        $this->fetchedAt = $fetchedAt;

        return $this->result;
    }
}

final readonly class FailingRawArticleSender implements MainApiRawArticleSenderInterface
{
    public function __construct(
        private \Throwable $exception,
    ) {
    }

    public function send(
        string $assignmentId,
        string $externalUrl,
        string $rawHtml,
        int $httpStatusCode,
        \DateTimeImmutable $fetchedAt,
    ): SendRawArticleResult {
        throw $this->exception;
    }
}

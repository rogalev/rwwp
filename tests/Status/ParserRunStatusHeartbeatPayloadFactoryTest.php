<?php

declare(strict_types=1);

namespace App\Tests\Status;

use App\Status\ParserRunStatusHeartbeatPayloadFactory;
use App\Status\RuntimeMetricsCollector;
use PHPUnit\Framework\TestCase;

final class ParserRunStatusHeartbeatPayloadFactoryTest extends TestCase
{
    public function testCreatesPartialPayloadWhenRunHasUsefulWorkAndFailures(): void
    {
        $payload = (new ParserRunStatusHeartbeatPayloadFactory())->create([
            'checkedAt' => '2026-05-02T10:00:00+00:00',
            'durationSeconds' => 7,
            'totalAssignments' => 4,
            'processedAssignments' => 3,
            'timedOutAssignments' => 1,
            'currentAssignmentId' => 'assignment-3',
            'currentSource' => 'BBC',
            'lastHeartbeatAt' => '2026-05-02T10:00:07+00:00',
            'skippedAssignments' => 2,
            'found' => 5,
            'queued' => 4,
            'sent' => 3,
            'failed' => 1,
            'httpStatusCodes' => ['200' => 3],
            'transportErrors' => 2,
            'stage' => 'raw_article_send',
            'lastError' => '',
        ]);

        self::assertSame('2026-05-02T10:00:00+00:00', $payload->checkedAt->format(\DateTimeInterface::ATOM));
        self::assertSame('partial', $payload->status);
        self::assertSame('', $payload->message);
        self::assertSame([
            'durationSeconds' => 7,
            'processedAssignments' => 3,
            'totalAssignments' => 4,
            'timedOutAssignments' => 1,
            'currentAssignmentId' => 'assignment-3',
            'currentSource' => 'BBC',
            'lastHeartbeatAt' => '2026-05-02T10:00:07+00:00',
            'skippedAssignments' => 2,
            'foundLinks' => 5,
            'queuedArticles' => 4,
            'acceptedRawArticles' => 3,
            'failedArticles' => 1,
            'httpStatusCodes' => ['200' => 3],
            'transportErrors' => 2,
            'stage' => 'raw_article_send',
            'agentVersion' => '0.1.0',
            'phpVersion' => PHP_VERSION,
            'gitCommit' => '',
            'capabilities' => ['rss_listing', 'html_listing', 'raw_html_delivery'],
        ], $payload->metrics);
    }

    public function testAddsAgentMetadataFromEnvironment(): void
    {
        putenv('PARSER_AGENT_VERSION=1.2.3');
        putenv('PARSER_AGENT_GIT_COMMIT=abc1234');

        try {
            $payload = (new ParserRunStatusHeartbeatPayloadFactory())->create([]);
        } finally {
            putenv('PARSER_AGENT_VERSION');
            putenv('PARSER_AGENT_GIT_COMMIT');
        }

        self::assertSame('1.2.3', $payload->metrics['agentVersion']);
        self::assertSame(PHP_VERSION, $payload->metrics['phpVersion']);
        self::assertSame('abc1234', $payload->metrics['gitCommit']);
        self::assertSame(['rss_listing', 'html_listing', 'raw_html_delivery'], $payload->metrics['capabilities']);
    }

    public function testAddsRuntimeMetricsWhenCollectorIsConfigured(): void
    {
        $payload = (new ParserRunStatusHeartbeatPayloadFactory(new RuntimeMetricsCollector(
            stateDsn: 'sqlite:'.sys_get_temp_dir().'/russiaww-parser-tests/'.bin2hex(random_bytes(8)).'/missing.sqlite',
            diagnosticLogPath: sys_get_temp_dir().'/missing-parser-diagnostic.ndjson',
            hostLabel: 'nl3 parser',
        )))->create([]);

        self::assertSame('nl3 parser', $payload->metrics['hostLabel']);
        self::assertArrayHasKey('hostname', $payload->metrics);
        self::assertArrayHasKey('diskTotalBytes', $payload->metrics);
        self::assertArrayHasKey('memoryTotalBytes', $payload->metrics);
        self::assertArrayHasKey('pendingQueueSize', $payload->metrics);
    }

    public function testCreatesErrorPayloadFromFailedStatus(): void
    {
        $payload = (new ParserRunStatusHeartbeatPayloadFactory())->create([
            'checkedAt' => '2026-05-02T10:00:00+00:00',
            'lastError' => 'Assignment failed.',
        ]);

        self::assertSame('error', $payload->status);
        self::assertSame('Assignment failed.', $payload->message);
        self::assertSame(0, $payload->metrics['foundLinks']);
        self::assertSame([], $payload->metrics['httpStatusCodes']);
    }

    public function testCreatesIdlePayloadWhenAllAssignmentsAreSkipped(): void
    {
        $payload = (new ParserRunStatusHeartbeatPayloadFactory())->create([
            'assignments' => 2,
            'skippedAssignments' => 2,
            'lastError' => '',
        ]);

        self::assertSame('idle', $payload->status);
    }

    public function testCreatesDegradedPayloadFromRetryableHttpStatuses(): void
    {
        $payload = (new ParserRunStatusHeartbeatPayloadFactory())->create([
            'httpStatusCodes' => ['429' => 1],
            'lastError' => '',
        ]);

        self::assertSame('degraded', $payload->status);
    }
}

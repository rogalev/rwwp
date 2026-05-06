<?php

declare(strict_types=1);

namespace App\Tests\Status;

use App\Status\ParserRunStatusHeartbeatPayloadFactory;
use PHPUnit\Framework\TestCase;

final class ParserRunStatusHeartbeatPayloadFactoryTest extends TestCase
{
    public function testCreatesPartialPayloadWhenRunHasUsefulWorkAndFailures(): void
    {
        $payload = (new ParserRunStatusHeartbeatPayloadFactory())->create([
            'checkedAt' => '2026-05-02T10:00:00+00:00',
            'durationSeconds' => 7,
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
            'skippedAssignments' => 2,
            'foundLinks' => 5,
            'queuedArticles' => 4,
            'acceptedRawArticles' => 3,
            'failedArticles' => 1,
            'httpStatusCodes' => ['200' => 3],
            'transportErrors' => 2,
            'stage' => 'raw_article_send',
        ], $payload->metrics);
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

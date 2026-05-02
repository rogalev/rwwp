<?php

declare(strict_types=1);

namespace App\Tests\Status;

use App\Status\ParserRunStatusHeartbeatPayloadFactory;
use PHPUnit\Framework\TestCase;

final class ParserRunStatusHeartbeatPayloadFactoryTest extends TestCase
{
    public function testCreatesOkPayloadFromSuccessfulStatus(): void
    {
        $payload = (new ParserRunStatusHeartbeatPayloadFactory())->create([
            'checkedAt' => '2026-05-02T10:00:00+00:00',
            'found' => 5,
            'sent' => 3,
            'failed' => 1,
            'httpStatusCodes' => ['200' => 3],
            'transportErrors' => 2,
            'lastError' => '',
        ]);

        self::assertSame('2026-05-02T10:00:00+00:00', $payload->checkedAt->format(\DateTimeInterface::ATOM));
        self::assertSame('ok', $payload->status);
        self::assertSame('', $payload->message);
        self::assertSame([
            'durationSeconds' => 0,
            'foundLinks' => 5,
            'acceptedRawArticles' => 3,
            'failedArticles' => 1,
            'httpStatusCodes' => ['200' => 3],
            'transportErrors' => 2,
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
}

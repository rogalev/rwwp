<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\RotatingUserAgentProvider;
use PHPUnit\Framework\TestCase;

final class RotatingUserAgentProviderTest extends TestCase
{
    public function testNextReturnsConfiguredUserAgent(): void
    {
        $userAgents = [
            'Mozilla/5.0 Firefox/120.0',
            'Mozilla/5.0 Chrome/120.0',
        ];

        $provider = new RotatingUserAgentProvider($userAgents);

        self::assertContains($provider->next(), $userAgents);
    }

    public function testConstructorRejectsEmptyUserAgentList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one User-Agent must be configured.');

        new RotatingUserAgentProvider([]);
    }
}

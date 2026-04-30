<?php

declare(strict_types=1);

namespace App\Tests\MainApi;

use App\MainApi\ParserAssignment;
use PHPUnit\Framework\TestCase;

final class ParserAssignmentTest extends TestCase
{
    public function testCreatesParserAssignment(): void
    {
        $assignment = new ParserAssignment(
            assignmentId: '0196a222-2222-7222-8222-222222222222',
            sourceId: '0196a111-1111-7111-8111-111111111111',
            sourceDisplayName: 'BBC',
            listingMode: 'rss',
            listingUrl: 'https://feeds.bbci.co.uk/news/world/rss.xml',
            articleMode: 'html',
            listingCheckIntervalSeconds: 300,
            articleFetchIntervalSeconds: 10,
            requestTimeoutSeconds: 15,
            config: ['titleSelector' => 'h1'],
        );

        self::assertSame('0196a222-2222-7222-8222-222222222222', $assignment->assignmentId);
        self::assertSame('0196a111-1111-7111-8111-111111111111', $assignment->sourceId);
        self::assertSame('BBC', $assignment->sourceDisplayName);
        self::assertSame('rss', $assignment->listingMode);
        self::assertSame('https://feeds.bbci.co.uk/news/world/rss.xml', $assignment->listingUrl);
        self::assertSame('html', $assignment->articleMode);
        self::assertSame(300, $assignment->listingCheckIntervalSeconds);
        self::assertSame(10, $assignment->articleFetchIntervalSeconds);
        self::assertSame(15, $assignment->requestTimeoutSeconds);
        self::assertSame(['titleSelector' => 'h1'], $assignment->config);
    }

    public function testRejectsBlankRequiredString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('assignmentId must not be blank.');

        new ParserAssignment(
            assignmentId: '',
            sourceId: '0196a111-1111-7111-8111-111111111111',
            sourceDisplayName: 'BBC',
            listingMode: 'rss',
            listingUrl: 'https://feeds.bbci.co.uk/news/world/rss.xml',
            articleMode: 'html',
            listingCheckIntervalSeconds: 300,
            articleFetchIntervalSeconds: 10,
            requestTimeoutSeconds: 15,
            config: [],
        );
    }

    public function testRejectsNonPositiveInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('listingCheckIntervalSeconds must be greater than zero.');

        new ParserAssignment(
            assignmentId: '0196a222-2222-7222-8222-222222222222',
            sourceId: '0196a111-1111-7111-8111-111111111111',
            sourceDisplayName: 'BBC',
            listingMode: 'rss',
            listingUrl: 'https://feeds.bbci.co.uk/news/world/rss.xml',
            articleMode: 'html',
            listingCheckIntervalSeconds: 0,
            articleFetchIntervalSeconds: 10,
            requestTimeoutSeconds: 15,
            config: [],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Image;

use App\Http\UserAgentProviderInterface;
use App\Image\ImageDownloader;
use App\MainApi\ImageDownloadTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ImageDownloaderTest extends TestCase
{
    public function testDownloadsImageToTemporaryFile(): void
    {
        $response = new MockResponse('image-bytes', [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'image/jpeg'],
        ]);
        $downloader = new ImageDownloader(new MockHttpClient($response), new FixedUserAgentProvider());

        $result = $downloader->download($this->task());

        try {
            self::assertFileExists($result->filePath);
            self::assertSame('image-bytes', file_get_contents($result->filePath));
            self::assertSame(200, $result->statusCode);
            self::assertSame('image/jpeg', $result->contentType);
            self::assertSame(11, $result->sizeBytes);
            self::assertSame('https://example.com/image.jpg', $response->getRequestUrl());
            self::assertContains('User-Agent: Test Browser', $response->getRequestOptions()['headers']);
            self::assertContains('Referer: https://example.com/news/1', $response->getRequestOptions()['headers']);
        } finally {
            if (is_file($result->filePath)) {
                unlink($result->filePath);
            }
        }
    }

    public function testFailsOnNonImageContentType(): void
    {
        $downloader = new ImageDownloader(new MockHttpClient(new MockResponse('html', [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'text/html'],
        ])), new FixedUserAgentProvider());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unexpected image content type "text/html".');

        $downloader->download($this->task());
    }

    public function testFailsWhenImageIsTooLarge(): void
    {
        $downloader = new ImageDownloader(new MockHttpClient(new MockResponse('too-large', [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'image/jpeg'],
        ])), new FixedUserAgentProvider());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Downloaded image is too large: 9 bytes.');

        $downloader->download($this->task(maxBytes: 8));
    }

    private function task(int $maxBytes = 5242880): ImageDownloadTask
    {
        return new ImageDownloadTask(
            id: '019f0000-0000-7000-8000-000000000001',
            sourceName: 'BBC / World',
            externalUrl: 'https://example.com/news/1',
            imageUrl: 'https://example.com/image.jpg',
            altText: null,
            timeoutSeconds: 20,
            maxBytes: $maxBytes,
        );
    }
}

final readonly class FixedUserAgentProvider implements UserAgentProviderInterface
{
    public function next(): string
    {
        return 'Test Browser';
    }
}

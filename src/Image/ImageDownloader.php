<?php

declare(strict_types=1);

namespace App\Image;

use App\Http\UserAgentProviderInterface;
use App\MainApi\ImageDownloadTask;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class ImageDownloader
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private UserAgentProviderInterface $userAgentProvider,
    ) {
    }

    public function download(ImageDownloadTask $task): ImageDownloadResult
    {
        $response = $this->httpClient->request('GET', $task->imageUrl, [
            'headers' => [
                'User-Agent' => $this->userAgentProvider->next(),
                'Accept' => 'image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Referer' => $task->externalUrl,
            ],
            'timeout' => $task->timeoutSeconds,
            'max_duration' => $task->timeoutSeconds,
        ]);

        $statusCode = $response->getStatusCode();
        $headers = $response->getHeaders(false);
        $contentType = $headers['content-type'][0] ?? null;

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException(sprintf('HTTP %d while downloading image.', $statusCode));
        }

        if ($contentType !== null && !str_starts_with(strtolower($contentType), 'image/')) {
            throw new \RuntimeException(sprintf('Unexpected image content type "%s".', $contentType));
        }

        $content = $response->getContent();
        $sizeBytes = strlen($content);
        if ($sizeBytes <= 0) {
            throw new \RuntimeException('Downloaded image is empty.');
        }

        if ($sizeBytes > $task->maxBytes) {
            throw new \RuntimeException(sprintf('Downloaded image is too large: %d bytes.', $sizeBytes));
        }

        $filePath = tempnam(sys_get_temp_dir(), 'rww-image-');
        if ($filePath === false) {
            throw new \RuntimeException('Unable to create temporary image file.');
        }

        file_put_contents($filePath, $content);

        return new ImageDownloadResult($filePath, $statusCode, $contentType, $sizeBytes);
    }
}

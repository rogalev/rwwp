<?php

declare(strict_types=1);

namespace App\Image;

use App\MainApi\MainApiImageDownloadTaskClient;

final readonly class ImageDownloadBatchProcessor implements ImageDownloadBatchProcessorInterface
{
    public function __construct(
        private MainApiImageDownloadTaskClient $tasks,
        private ImageDownloader $downloader,
    ) {
    }

    public function process(int $limit): ImageDownloadBatchResult
    {
        $claimedTasks = $this->tasks->claim($limit);
        $downloaded = 0;
        $failed = 0;

        foreach ($claimedTasks as $task) {
            $filePath = null;

            try {
                $result = $this->downloader->download($task);
                $filePath = $result->filePath;
                $this->tasks->complete($task, $result->filePath);
                ++$downloaded;
            } catch (\Throwable $exception) {
                ++$failed;
                $this->tasks->fail($task, $exception->getMessage(), [
                    'imageUrl' => $task->imageUrl,
                    'externalUrl' => $task->externalUrl,
                ]);
            } finally {
                if ($filePath !== null && is_file($filePath)) {
                    unlink($filePath);
                }
            }
        }

        return new ImageDownloadBatchResult(\count($claimedTasks), $downloaded, $failed);
    }
}

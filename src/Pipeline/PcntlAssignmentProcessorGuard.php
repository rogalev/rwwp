<?php

declare(strict_types=1);

namespace App\Pipeline;

use App\MainApi\ParserAssignment;
use App\Schedule\AssignmentScheduleDecision;

final readonly class PcntlAssignmentProcessorGuard implements AssignmentProcessorGuardInterface
{
    public function __construct(
        private ScheduledAssignmentProcessor $processor,
        private int $timeoutSeconds,
    ) {
        if ($this->timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('timeoutSeconds must be greater than zero.');
        }
    }

    public function process(
        ParserAssignment $assignment,
        AssignmentScheduleDecision $scheduleDecision,
        int $limit,
    ): ScheduledAssignmentProcessingResult {
        $this->assertPcntlAvailable();

        $resultFile = tempnam(sys_get_temp_dir(), 'russiaww-assignment-result-');
        if ($resultFile === false) {
            throw new \RuntimeException('Unable to create assignment result file.');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            @unlink($resultFile);

            throw new \RuntimeException('Unable to fork assignment worker process.');
        }

        if ($pid === 0) {
            $this->runChild($assignment, $scheduleDecision, $limit, $resultFile);
        }

        return $this->waitChild($pid, $resultFile, $assignment, $this->stage($scheduleDecision));
    }

    private function runChild(
        ParserAssignment $assignment,
        AssignmentScheduleDecision $scheduleDecision,
        int $limit,
        string $resultFile,
    ): never {
        try {
            $result = $this->processor->process($assignment, $scheduleDecision, $limit);
            file_put_contents($resultFile, serialize([
                'ok' => true,
                'result' => $result,
            ]));

            exit(0);
        } catch (\Throwable $exception) {
            file_put_contents($resultFile, serialize([
                'ok' => false,
                'message' => $exception->getMessage(),
                'class' => $exception::class,
            ]));

            exit(1);
        }
    }

    private function waitChild(
        int $pid,
        string $resultFile,
        ParserAssignment $assignment,
        string $stage,
    ): ScheduledAssignmentProcessingResult {
        $deadline = microtime(true) + $this->timeoutSeconds;

        try {
            while (true) {
                $finishedPid = pcntl_waitpid($pid, $status, WNOHANG);
                if ($finishedPid === $pid) {
                    return $this->readResult($resultFile);
                }

                if ($finishedPid === -1) {
                    throw new \RuntimeException('Unable to wait assignment worker process.');
                }

                if (microtime(true) >= $deadline) {
                    posix_kill($pid, SIGKILL);
                    pcntl_waitpid($pid, $status);

                    throw new AssignmentTimeoutException(
                        assignmentId: $assignment->assignmentId,
                        source: $assignment->sourceDisplayName,
                        timeoutSeconds: $this->timeoutSeconds,
                        stage: $stage,
                    );
                }

                usleep(100_000);
            }
        } finally {
            @unlink($resultFile);
        }
    }

    private function readResult(string $resultFile): ScheduledAssignmentProcessingResult
    {
        $payload = file_get_contents($resultFile);
        if ($payload === false || $payload === '') {
            throw new \RuntimeException('Assignment worker finished without result payload.');
        }

        $result = unserialize($payload, ['allowed_classes' => [ScheduledAssignmentProcessingResult::class]]);
        if (!\is_array($result)) {
            throw new \RuntimeException('Assignment worker returned invalid result payload.');
        }

        if (($result['ok'] ?? false) === true && ($result['result'] ?? null) instanceof ScheduledAssignmentProcessingResult) {
            return $result['result'];
        }

        $message = \is_string($result['message'] ?? null) && $result['message'] !== ''
            ? $result['message']
            : 'Assignment worker failed.';

        throw new \RuntimeException($message);
    }

    private function stage(AssignmentScheduleDecision $scheduleDecision): string
    {
        if ($scheduleDecision->listingDue) {
            return 'listing';
        }

        if ($scheduleDecision->articleFetchDue) {
            return 'article_fetch';
        }

        return 'idle';
    }

    private function assertPcntlAvailable(): void
    {
        foreach (['pcntl_fork', 'pcntl_waitpid', 'posix_kill'] as $function) {
            if (!\function_exists($function)) {
                throw new \RuntimeException(sprintf('Function "%s" is required for assignment timeout guard.', $function));
            }
        }
    }
}

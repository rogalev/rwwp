<?php

declare(strict_types=1);

namespace App\Command;

use App\MainApi\MainApiAssignmentsProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'parser:self-check',
    description: 'Проверяет готовность parser-agent к запуску на сервере.',
)]
final class SelfCheckCommand extends Command
{
    public function __construct(
        private readonly string $stateDsn,
        private readonly string $runStatusPath,
        private readonly MainApiAssignmentsProviderInterface $assignmentsProvider,
        private readonly ?bool $pcntlAvailableForTest = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $checks = [
            $this->checkPcntl(),
            $this->checkSqlite(),
            $this->checkWritablePath('SQLite state', $this->sqlitePath($this->stateDsn)),
            $this->checkWritablePath('run status', $this->runStatusPath),
            $this->checkMainApi(),
        ];

        $io->table(
            ['Проверка', 'Статус', 'Детали'],
            array_map(
                static fn (array $check): array => [$check['name'], $check['ok'] ? 'ok' : 'failed', $check['details']],
                $checks,
            ),
        );

        foreach ($checks as $check) {
            if (!$check['ok']) {
                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{name: string, ok: bool, details: string}
     */
    private function checkPcntl(): array
    {
        $ok = $this->pcntlAvailableForTest ?? \function_exists('pcntl_fork');

        return [
            'name' => 'pcntl',
            'ok' => $ok,
            'details' => $ok ? 'pcntl_fork доступен' : 'pcntl_fork недоступен, assignment timeout guard не сможет работать',
        ];
    }

    /**
     * @return array{name: string, ok: bool, details: string}
     */
    private function checkSqlite(): array
    {
        $drivers = \PDO::getAvailableDrivers();
        $ok = \in_array('sqlite', $drivers, true);

        return [
            'name' => 'sqlite',
            'ok' => $ok,
            'details' => $ok ? 'PDO sqlite доступен' : 'PDO sqlite driver не установлен',
        ];
    }

    /**
     * @return array{name: string, ok: bool, details: string}
     */
    private function checkWritablePath(string $name, string $path): array
    {
        if ($path === '') {
            return [
                'name' => $name,
                'ok' => false,
                'details' => 'путь не задан',
            ];
        }

        $directory = is_dir($path) ? $path : dirname($path);
        $ok = is_dir($directory) && is_writable($directory);

        return [
            'name' => $name,
            'ok' => $ok,
            'details' => $ok ? $directory.' доступен для записи' : $directory.' недоступен для записи',
        ];
    }

    /**
     * @return array{name: string, ok: bool, details: string}
     */
    private function checkMainApi(): array
    {
        try {
            $assignments = $this->assignmentsProvider->list();
        } catch (\Throwable $exception) {
            return [
                'name' => 'main api',
                'ok' => false,
                'details' => $exception->getMessage(),
            ];
        }

        return [
            'name' => 'main api',
            'ok' => true,
            'details' => sprintf('доступен, назначений: %d', \count($assignments)),
        ];
    }

    private function sqlitePath(string $dsn): string
    {
        if (!str_starts_with($dsn, 'sqlite:')) {
            return '';
        }

        return substr($dsn, strlen('sqlite:'));
    }
}

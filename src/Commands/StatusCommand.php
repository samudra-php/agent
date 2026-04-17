<?php

declare(strict_types=1);

namespace Samudra\Agent\Commands;

use Samudra\Agent\AgentConfig;
use Samudra\Agent\InstallationManager;
use Samudra\Agent\PlatformClient;
use Throwable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Команда проверки текущего состояния агента.
 */
#[AsCommand(name: 'status', description: 'Показывает состояние конфигурации, API и последнего run')]
final class StatusCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'run-id',
            null,
            InputOption::VALUE_REQUIRED,
            'Явный run_id для запроса статуса (иначе используется last_run_id из .samudra.yml)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Samudra Status');

        $config = AgentConfig::load();
        $installationManager = new InstallationManager();
        $installationId = $installationManager->currentInstallationId();
        $token = $config->authToken();
        $tokenSource = $config->authTokenSource() ?? 'missing';
        $projectId = $config->projectId();
        $platformUrl = $config->platformUrl();

        $io->section('Local state');
        $io->definitionList(
            ['Config file' => $config->hasConfigFile() ? $config->configPath() : 'not found'],
            ['Platform URL' => $platformUrl],
            ['Project ID' => $projectId ?? 'not set'],
            ['Installation ID' => $installationId ?? 'not created'],
            ['Token' => $token !== null ? 'present (' . $tokenSource . ')' : 'missing'],
            ['Last run' => $config->lastRunId() ?? 'not set'],
        );

        $client = new PlatformClient($platformUrl);
        $isReady = true;

        [$healthState, $healthError] = $this->checkHealth($client);
        if ($healthError !== null) {
            $isReady = false;
        }

        [$authState, $authError] = $this->checkAuth($client, $token);
        if ($authError !== null) {
            $isReady = false;
        }

        if ($projectId === null) {
            $isReady = false;
        }

        $io->section('Platform connectivity');
        $io->definitionList(
            ['Health' => $healthError === null ? $healthState : $healthState . ': ' . $healthError],
            ['Auth' => $authError === null ? $authState : $authState . ': ' . $authError],
            ['Project registration' => $projectId !== null ? 'ok' : 'missing (run samudra register)'],
        );

        $runIdOption = $input->getOption('run-id');
        $runId = is_string($runIdOption) && trim($runIdOption) !== ''
            ? trim($runIdOption)
            : $config->lastRunId();

        if ($runId !== null) {
            $this->renderRunStatus($io, $client, $token, $runId);
        } else {
            $io->note('Last run ещё не сохранён. После upload/extract run_id появится автоматически.');
        }

        if ($isReady) {
            $io->success('Агент готов к register/extract/upload.');

            return Command::SUCCESS;
        }

        $io->warning('Конфигурация неполная. Завершите init/login/register перед рабочим запуском.');

        return Command::FAILURE;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function checkHealth(PlatformClient $client): array
    {
        try {
            $health = $client->health();
            if (($health['status'] ?? null) !== 'ok') {
                return ['unexpected payload', 'unexpected health payload'];
            }

            return ['ok', null];
        } catch (Throwable $e) {
            return ['error', $e->getMessage()];
        }
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function checkAuth(PlatformClient $client, ?string $token): array
    {
        if ($token === null) {
            return ['missing', 'token missing'];
        }

        try {
            $client->setToken($token);
            $client->assertTokenIsValid();

            return ['ok', null];
        } catch (Throwable $e) {
            return ['error', $e->getMessage()];
        }
    }

    private function renderRunStatus(SymfonyStyle $io, PlatformClient $client, ?string $token, string $runId): void
    {
        if ($token === null) {
            $io->warning("Невозможно запросить run {$runId}: токен отсутствует");

            return;
        }

        $client->setToken($token);

        try {
            $runStatus = $client->fetchRunStatus($runId);
        } catch (Throwable $e) {
            $io->warning("Не удалось получить run {$runId}: " . $e->getMessage());

            return;
        }

        $errors = $runStatus['errors'] ?? [];
        $followUpRequest = $runStatus['follow_up_request'] ?? [];

        $io->section('Run status');
        $io->definitionList(
            ['Run ID' => (string) ($runStatus['run_id'] ?? $runId)],
            ['Status' => (string) ($runStatus['status'] ?? 'unknown')],
            ['Effective mode' => (string) ($runStatus['effective_mode'] ?? 'unknown')],
            ['Follow-up request' => is_array($followUpRequest) && $followUpRequest !== [] ? 'yes' : 'no'],
            ['Errors count' => is_array($errors) ? (string) count($errors) : '0'],
            ['Updated at' => (string) ($runStatus['updated_at'] ?? 'unknown')],
        );
    }
}

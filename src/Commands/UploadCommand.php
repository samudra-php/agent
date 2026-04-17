<?php

declare(strict_types=1);

namespace Samudra\Agent\Commands;

use Throwable;
use Samudra\Agent\AgentConfig;
use Samudra\Agent\InstallationManager;
use Samudra\Agent\PlatformClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Команда отправки bundle на платформу.
 */
#[AsCommand(name: 'upload', description: 'Отправка существующего bundle на платформу')]
final class UploadCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument(
            'file',
            InputArgument::REQUIRED,
            'Путь к JSON-файлу bundle',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Samudra Upload');

        $file = $input->getArgument('file');
        if (!is_file($file)) {
            $io->error("Файл не найден: {$file}");

            return Command::FAILURE;
        }

        $bundleJson = file_get_contents($file);
        if (!is_string($bundleJson) || $bundleJson === '') {
            $io->error("Не удалось прочитать bundle: {$file}");

            return Command::FAILURE;
        }

        // Загружаем конфигурацию
        $config = AgentConfig::load();
        if ($config->projectId() === null) {
            $io->error('Проект не зарегистрирован. Выполните: samudra register');

            return Command::FAILURE;
        }

        // Отправляем
        $token = $config->authToken();
        if ($token === null) {
            $io->error('Токен не найден. Выполните samudra login или установите SAMUDRA_TOKEN');

            return Command::FAILURE;
        }

        $io->text('Отправка bundle...');
        $client = new PlatformClient($config->platformUrl());
        $client->setToken($token);
        $installationManager = new InstallationManager();

        try {
            $client->registerInstallation(
                installationId: $installationManager->getInstallationId(),
                projectId: (string) $config->projectId(),
                hostname: gethostname() ?: null,
            );
            $result = $client->uploadBundle($config->projectId(), $bundleJson);
            if (isset($result['run_id']) && is_string($result['run_id'])) {
                $config->setLastRunId($result['run_id']);
            }
            $io->success("Bundle отправлен. Run ID: {$result['run_id']}");
        } catch (Throwable $e) {
            $io->error('Ошибка отправки: ' . $e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

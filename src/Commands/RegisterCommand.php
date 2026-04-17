<?php

declare(strict_types=1);

namespace Samudra\Agent\Commands;

use Samudra\Agent\AgentCompatibilityGuard;
use Throwable;
use Samudra\Agent\AgentConfig;
use Samudra\Agent\FingerprintCalculator;
use Samudra\Agent\InstallationManager;
use Samudra\Agent\PlatformClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Команда регистрации проекта.
 */
#[AsCommand(name: 'register', description: 'Регистрация проекта на платформе')]
final class RegisterCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Samudra Register');

        // Загружаем конфигурацию
        $config = AgentConfig::load();

        // Вычисляем fingerprint
        $fingerprintCalc = new FingerprintCalculator();
        $fingerprint = $fingerprintCalc->calculate($config->projectRoot);

        $io->text("Fingerprint: {$fingerprint}");

        // Получаем токен
        $token = $config->authToken();
        if ($token === null) {
            $io->error('Токен не найден. Выполните samudra login или установите SAMUDRA_TOKEN');

            return Command::FAILURE;
        }

        // Регистрируем проект
        $client = new PlatformClient($config->platformUrl());
        $client->setToken($token);
        $compatibilityGuard = new AgentCompatibilityGuard();

        $io->text('Регистрация проекта...');

        try {
            if (!$compatibilityGuard->render($client->fetchAgentCompatibility(), $io)) {
                return Command::FAILURE;
            }

            $result = $client->registerProject($fingerprint);
            $config->setProjectId($result['project_id']);

            $installationManager = new InstallationManager();
            $installationId = $installationManager->getInstallationId();
            $client->registerInstallation(
                installationId: $installationId,
                projectId: $result['project_id'],
                hostname: gethostname() ?: null,
            );

            $io->success("Проект зарегистрирован. Project ID: {$result['project_id']}");
        } catch (Throwable $e) {
            $io->error('Ошибка регистрации: ' . $e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}

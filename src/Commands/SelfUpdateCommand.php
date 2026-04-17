<?php

declare(strict_types=1);

namespace Samudra\Agent\Commands;

use Samudra\Agent\AgentCompatibilityGuard;
use Samudra\Agent\AgentConfig;
use Samudra\Agent\AgentVersion;
use Samudra\Agent\PlatformClient;
use Samudra\Agent\SelfUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Команда обновления установленного агента.
 */
#[AsCommand(name: 'self-update', description: 'Обновляет установленный агент до нового release')]
final class SelfUpdateCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'target-version',
            null,
            InputOption::VALUE_REQUIRED,
            'Конкретная версия, например 0.1.0 или v0.1.0',
        );
        $this->addOption(
            'url',
            null,
            InputOption::VALUE_REQUIRED,
            'Явный URL до samudra.phar',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Samudra Self Update');

        $versionOption = $this->readOption($input->getOption('target-version'));
        $urlOption = $this->readOption($input->getOption('url'));

        if ($versionOption !== null && AgentVersion::isCurrent($versionOption) && $urlOption === null) {
            $io->success('Агент уже на версии ' . AgentVersion::CURRENT);

            return Command::SUCCESS;
        }

        $updater = new SelfUpdater();

        try {
            $installedPath = $updater->installedBinaryPath();
            $downloadUrl = $this->resolveDownloadUrl($urlOption, $versionOption);
            $updater->update($downloadUrl);
        } catch (Throwable $e) {
            $io->error('Не удалось обновить агент: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Current version' => AgentVersion::CURRENT],
            ['Installed binary' => $installedPath],
            ['Source URL' => $downloadUrl],
        );
        $io->success('Агент обновлён. Перезапусти команду `samudra --version`, чтобы увидеть новую версию.');

        return Command::SUCCESS;
    }

    private function resolveDownloadUrl(?string $urlOption, ?string $versionOption): string
    {
        if ($urlOption !== null) {
            return $urlOption;
        }

        if ($versionOption !== null) {
            return AgentVersion::releaseDownloadUrl($versionOption);
        }

        return $this->platformPreferredDownloadUrl() ?? AgentVersion::DEFAULT_RELEASE_URL;
    }

    private function platformPreferredDownloadUrl(): ?string
    {
        $config = AgentConfig::load();

        try {
            $client = new PlatformClient($config->platformUrl());
            $status = (new AgentCompatibilityGuard())->resolve($client);

            return $status->downloadUrl ?? AgentVersion::DEFAULT_RELEASE_URL;
        } catch (Throwable) {
            return null;
        }
    }

    private function readOption(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

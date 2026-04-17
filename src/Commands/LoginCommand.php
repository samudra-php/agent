<?php

declare(strict_types=1);

namespace Samudra\Agent\Commands;

use RuntimeException;
use Samudra\Agent\AgentCompatibilityGuard;
use Throwable;
use Samudra\Agent\AgentConfig;
use Samudra\Agent\PlatformClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Команда входа и сохранения platform URL + token.
 */
#[AsCommand(name: 'login', description: 'Сохраняет URL платформы и токен агента')]
final class LoginCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'url',
            null,
            InputOption::VALUE_REQUIRED,
            'URL платформы, например http://localhost:18000',
        );
        $this->addOption(
            'token',
            null,
            InputOption::VALUE_REQUIRED,
            'Токен доступа (иначе будет запрошен интерактивно)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Samudra Login');

        $config = AgentConfig::load();
        $platformUrl = $this->resolvePlatformUrl($config, $input->getOption('url'));
        $token = $this->resolveToken($config, $input->getOption('token'), $io);

        if ($token === null) {
            $io->error('Токен не задан');

            return Command::FAILURE;
        }

        $client = new PlatformClient($platformUrl);
        $compatibilityGuard = new AgentCompatibilityGuard();

        try {
            $health = $client->health();
            if (($health['status'] ?? null) !== 'ok') {
                $io->error('Platform health-check вернул неожиданный ответ');

                return Command::FAILURE;
            }

            $compatibility = $compatibilityGuard->resolve($client, $health);
            if (!$compatibilityGuard->render($compatibility, $io)) {
                return Command::FAILURE;
            }

            $client->setToken($token);
            $client->assertTokenIsValid();
        } catch (Throwable $e) {
            $io->error('Не удалось выполнить login: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $config->setPlatformUrl($platformUrl);
        $config->setAuthToken($token);

        $io->success('Login успешен. URL и токен сохранены в .samudra.yml');
        $io->definitionList(
            ['Platform URL' => $platformUrl],
            ['Token source' => 'saved'],
        );

        return Command::SUCCESS;
    }

    private function resolvePlatformUrl(AgentConfig $config, mixed $urlOption): string
    {
        $candidate = is_string($urlOption) && trim($urlOption) !== ''
            ? $urlOption
            : $config->platformUrl();

        return $this->normalizePlatformUrl($candidate);
    }

    private function resolveToken(AgentConfig $config, mixed $tokenOption, SymfonyStyle $io): ?string
    {
        if (is_string($tokenOption) && trim($tokenOption) !== '') {
            return trim($tokenOption);
        }

        $existingToken = $config->authToken();
        if ($existingToken !== null) {
            return $existingToken;
        }

        $promptedToken = $io->askHidden('Введите SAMUDRA token');
        if (!is_string($promptedToken)) {
            return null;
        }

        $promptedToken = trim($promptedToken);

        return $promptedToken !== '' ? $promptedToken : null;
    }

    private function normalizePlatformUrl(string $url): string
    {
        $normalized = rtrim(trim($url), '/');
        $parts = parse_url($normalized);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Некорректный platform URL');
        }

        return $normalized;
    }
}

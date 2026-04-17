<?php

declare(strict_types=1);

namespace Samudra\Agent\Commands;

use RuntimeException;
use Samudra\Agent\AgentConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Команда инициализации локальной конфигурации агента.
 */
#[AsCommand(name: 'init', description: 'Создаёт или дополняет .samudra.yml')]
final class InitCommand extends Command
{
    /**
     * @var array<string, mixed>
     */
    private const array DEFAULTS = [
        'platform' => [
            'url' => 'http://localhost:18000',
        ],
        'bundle' => [
            'profile' => 'private',
        ],
        'indexing' => [
            'include' => ['app', 'src', 'routes', 'config'],
            'exclude' => ['vendor', 'node_modules', 'storage', 'bootstrap/cache'],
            'include_tests' => false,
        ],
    ];

    protected function configure(): void
    {
        $this->addOption(
            'platform-url',
            null,
            InputOption::VALUE_REQUIRED,
            'URL платформы, например http://localhost:18000',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Samudra Init');

        $config = AgentConfig::load();
        $wasExisting = $config->hasConfigFile();

        $merged = $this->mergeWithDefaults($config->raw, self::DEFAULTS);
        $platformUrl = $input->getOption('platform-url');
        if (is_string($platformUrl) && trim($platformUrl) !== '') {
            $merged['platform']['url'] = $this->normalizePlatformUrl($platformUrl);
        }

        $config->raw = $merged;
        $config->save();

        $io->success(($wasExisting ? 'Конфиг обновлён' : 'Конфиг создан') . ': ' . $config->configPath());
        $io->definitionList(
            ['Platform URL' => (string) ($config->raw['platform']['url'] ?? '')],
            ['Bundle profile' => (string) ($config->raw['bundle']['profile'] ?? '')],
            ['Include tests' => (bool) ($config->raw['indexing']['include_tests'] ?? false) ? 'yes' : 'no'],
        );

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function mergeWithDefaults(array $raw, array $defaults): array
    {
        foreach ($defaults as $key => $defaultValue) {
            if (!array_key_exists($key, $raw)) {
                $raw[$key] = $defaultValue;
                continue;
            }

            if (is_array($defaultValue)) {
                if (!is_array($raw[$key])) {
                    $raw[$key] = $defaultValue;
                    continue;
                }

                $raw[$key] = $this->mergeWithDefaults($raw[$key], $defaultValue);
            }
        }

        return $raw;
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

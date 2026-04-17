<?php

declare(strict_types=1);

namespace Samudra\Agent\Commands;

use Throwable;
use Samudra\IndexBundleContract\BundleProfile;
use Samudra\IndexBundleContract\RunMode;
use Samudra\Agent\AgentConfig;
use Samudra\Agent\InstallationManager;
use Samudra\Agent\PlatformClient;
use Samudra\Extractor\BundleSerializer;
use Samudra\Extractor\ExtractResult;
use Samudra\Extractor\Extractor;
use Samudra\Extractor\FileFinder;
use Samudra\Extractor\LaravelEnrichment\LaravelEnrichmentExtractor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Команда полного извлечения.
 */
#[AsCommand(name: 'extract', description: 'Полное извлечение фактов из проекта')]
final class ExtractCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'incremental',
            null,
            InputOption::VALUE_NONE,
            'Инкрементальный режим (только изменённые файлы)',
        );
        $this->addOption(
            'base-run-id',
            null,
            InputOption::VALUE_REQUIRED,
            'Base run ID для incremental режима',
        );
        $this->addOption(
            'changed-path',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Изменённый путь для incremental режима; можно указывать несколько раз',
        );
        $this->addOption(
            'output',
            'o',
            InputOption::VALUE_REQUIRED,
            'Вывести bundle в файл вместо отправки на платформу',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Samudra Extract');

        // Загружаем конфигурацию
        $config = AgentConfig::load();
        if ($config->projectId() === null) {
            $io->error('Проект не зарегистрирован. Выполните: samudra register');

            return Command::FAILURE;
        }

        $projectRoot = $config->projectRoot;
        $io->section("Project root: {$projectRoot}");

        $mode = $input->getOption('incremental') ? RunMode::Incremental : RunMode::Full;
        $changedPaths = $this->resolveChangedPaths(
            projectRoot: $projectRoot,
            rawPaths: $input->getOption('changed-path'),
        );

        if ($mode === RunMode::Incremental && $changedPaths === []) {
            $io->error('Для incremental режима требуется хотя бы один --changed-path');

            return Command::FAILURE;
        }

        if ($mode === RunMode::Incremental && !$input->getOption('base-run-id')) {
            $io->error('Для incremental режима требуется --base-run-id');

            return Command::FAILURE;
        }

        $files = $mode === RunMode::Incremental
            ? array_values(array_filter($changedPaths, static fn (string $path): bool => str_ends_with($path, '.php')))
            : $this->discoverProjectFiles($config, $projectRoot, $io);

        if ($mode === RunMode::Incremental) {
            $io->text('Incremental paths: ' . count($changedPaths));
            $io->text('PHP файлов для extraction: ' . count($files));
        }

        if ($files === [] && $changedPaths === []) {
            $io->warning('Нет файлов для извлечения');

            return Command::SUCCESS;
        }

        // Извлекаем факты
        $io->text('Извлечение фактов...');
        $extractor = new Extractor();
        $results = [];
        $progressBar = $io->createProgressBar(max(count($files), 1));

        foreach ($files as $file) {
            $results[] = $extractor->extractFile($file, $this->relativeToProjectRoot($file, $projectRoot));
            $progressBar->advance();
        }

        foreach ($this->nonPhpChangedPaths($changedPaths) as $nonPhpPath) {
            $results[] = new ExtractResult(
                filePath: $nonPhpPath,
                parsedOk: true,
                symbols: [],
                callSites: [],
                references: [],
                diagnostics: [],
                extractMs: 0,
            );
        }

        $progressBar->finish();
        $io->newLine();

        // Сериализуем bundle
        $io->text('Сериализация bundle...');
        $serializer = new BundleSerializer();
        $installationManager = new InstallationManager();
        $installationId = $installationManager->getInstallationId();
        $enrichmentExtractor = new LaravelEnrichmentExtractor();
        $enrichment = $enrichmentExtractor->extract($projectRoot);

        $bundle = $serializer->serialize(
            results: $results,
            profile: BundleProfile::from($config->bundleProfile()),
            bundleId: $this->generateBundleId(),
            projectId: $config->projectId(),
            installationId: $installationId,
            mode: $mode,
            baseRunId: $input->getOption('base-run-id') ?: null,
            routes: $enrichment->routes,
            bindings: $enrichment->bindings,
            projectRoot: $projectRoot,
        );

        // Вывод или отправка
        $outputFile = $input->getOption('output');
        if ($outputFile !== null) {
            file_put_contents($outputFile, $bundle->toJson());
            $io->success("Bundle сохранён в {$outputFile}");

            return Command::SUCCESS;
        }

        // Отправляем на платформу
        $io->text('Отправка bundle на платформу...');
        $token = $config->authToken();
        if ($token === null) {
            $io->error('Токен не найден. Выполните samudra login или установите SAMUDRA_TOKEN');

            return Command::FAILURE;
        }

        $client = new PlatformClient($config->platformUrl());
        $client->setToken($token);

        try {
            $client->registerInstallation(
                installationId: $installationId,
                projectId: (string) $config->projectId(),
                hostname: gethostname() ?: null,
            );
            $result = $client->uploadBundle($config->projectId(), $bundle->toJson());
            if (isset($result['run_id']) && is_string($result['run_id'])) {
                $config->setLastRunId($result['run_id']);
            }
            $io->success("Bundle отправлен. Run ID: {$result['run_id']}");
        } catch (Throwable $e) {
            $io->error('Ошибка отправки bundle: ' . $e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $rawPaths
     * @return list<string>
     */
    private function resolveChangedPaths(string $projectRoot, array $rawPaths): array
    {
        $resolved = [];

        foreach ($rawPaths as $rawPath) {
            $candidate = str_starts_with($rawPath, '/')
                ? $rawPath
                : $projectRoot . '/' . ltrim($rawPath, '/');
            $realPath = realpath($candidate);

            if ($realPath !== false && is_file($realPath)) {
                $resolved[] = $realPath;
            }
        }

        return array_values(array_unique($resolved));
    }

    private function discoverProjectFiles(AgentConfig $config, string $projectRoot, SymfonyStyle $io): array
    {
        $io->text('Поиск PHP-файлов...');
        $finder = new FileFinder(
            projectRoot: $projectRoot,
            includeDirs: $config->includeDirs(),
            excludeDirs: $config->excludeDirs(),
            includeTests: $config->includeTests(),
        );
        $files = $finder->find();
        $io->text('Найдено файлов: ' . count($files));

        return $files;
    }

    /**
     * @param list<string> $changedPaths
     * @return list<string>
     */
    private function nonPhpChangedPaths(array $changedPaths): array
    {
        return array_values(array_filter(
            $changedPaths,
            static fn (string $path): bool => !str_ends_with($path, '.php'),
        ));
    }

    private function generateBundleId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function relativeToProjectRoot(string $filePath, string $projectRoot): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $normalizedPath = str_replace('\\', '/', $filePath);

        if (str_starts_with($normalizedPath, $normalizedRoot . '/')) {
            return substr($normalizedPath, strlen($normalizedRoot) + 1);
        }

        return $normalizedPath;
    }
}

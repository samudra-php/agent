<?php

declare(strict_types=1);

namespace Samudra\Agent;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Конфигурация агента из .samudra.yml.
 */
final class AgentConfig
{
    private const string DEFAULT_CONFIG_FILE = '.samudra.yml';

    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public string $projectRoot,
        public array $raw,
    ) {
    }

    public function configPath(): string
    {
        return $this->projectRoot . '/' . self::DEFAULT_CONFIG_FILE;
    }

    public function hasConfigFile(): bool
    {
        return is_file($this->configPath());
    }

    public function platformUrl(): string
    {
        return $this->readNestedString('platform', 'url') ?? 'http://localhost:18000';
    }

    public function setPlatformUrl(string $platformUrl): void
    {
        $normalized = rtrim(trim($platformUrl), '/');
        if ($normalized === '') {
            throw new RuntimeException('Platform URL не может быть пустым');
        }

        $this->raw['platform'] ??= [];
        $this->raw['platform']['url'] = $normalized;
        $this->save();
    }

    public function projectId(): ?string
    {
        return $this->readNestedString('project_id');
    }

    public function setProjectId(string $projectId): void
    {
        $this->raw['project_id'] = $projectId;
        $this->save();
    }

    public function lastRunId(): ?string
    {
        return $this->readNestedString('last_run_id');
    }

    public function setLastRunId(string $runId): void
    {
        $this->raw['last_run_id'] = $runId;
        $this->save();
    }

    public function storedAuthToken(): ?string
    {
        return $this->readNestedString('auth', 'token');
    }

    public function authToken(): ?string
    {
        $storedToken = $this->storedAuthToken();
        if ($storedToken !== null) {
            return $storedToken;
        }

        $envToken = getenv('SAMUDRA_TOKEN');
        if (!is_string($envToken)) {
            return null;
        }

        $envToken = trim($envToken);

        return $envToken !== '' ? $envToken : null;
    }

    public function authTokenSource(): ?string
    {
        if ($this->storedAuthToken() !== null) {
            return 'config';
        }

        if ($this->authToken() !== null) {
            return 'env';
        }

        return null;
    }

    public function setAuthToken(string $token): void
    {
        $normalized = trim($token);
        if ($normalized === '') {
            throw new RuntimeException('Токен не может быть пустым');
        }

        $this->raw['auth'] ??= [];
        $this->raw['auth']['token'] = $normalized;
        $this->save();
    }

    public function bundleProfile(): string
    {
        return $this->raw['bundle']['profile'] ?? 'private';
    }

    /**
     * @return list<string>
     */
    public function includeDirs(): array
    {
        $dirs = $this->raw['indexing']['include'] ?? [];
        if ($dirs === []) {
            // Директории по умолчанию
            $dirs = [
                $this->projectRoot . '/app',
                $this->projectRoot . '/src',
                $this->projectRoot . '/routes',
                $this->projectRoot . '/config',
            ];
        }

        return array_map(
            fn (string $d): string => $this->resolvePath($d),
            $dirs,
        );
    }

    /**
     * @return list<string>
     */
    public function excludeDirs(): array
    {
        $dirs = $this->raw['indexing']['exclude'] ?? [];

        return array_map(
            fn (string $d): string => $this->resolvePath($d),
            $dirs,
        );
    }

    public function includeTests(): bool
    {
        return (bool) ($this->raw['indexing']['include_tests'] ?? false);
    }

    /**
     * Загружает конфигурацию из файла.
     */
    public static function load(?string $projectRoot = null): self
    {
        $projectRoot ??= getcwd();
        if (!is_string($projectRoot) || $projectRoot === '') {
            throw new RuntimeException('Не удалось определить project root');
        }

        $configFile = $projectRoot . '/' . self::DEFAULT_CONFIG_FILE;

        if (!is_file($configFile)) {
            return new self(
                projectRoot: $projectRoot,
                raw: [],
            );
        }

        $raw = Yaml::parseFile($configFile);
        if (!is_array($raw)) {
            throw new RuntimeException('Invalid .samudra.yml format');
        }

        return new self(
            projectRoot: $projectRoot,
            raw: $raw,
        );
    }

    /**
     * Сохраняет конфигурацию в файл.
     */
    public function save(): void
    {
        $configFile = $this->configPath();
        $yaml = Yaml::dump($this->raw, 10, 2);
        file_put_contents($configFile, $yaml);
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->projectRoot . '/' . ltrim($path, '/');
    }

    private function readNestedString(string ...$path): ?string
    {
        $value = $this->raw;

        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

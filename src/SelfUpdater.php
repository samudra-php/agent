<?php

declare(strict_types=1);

namespace Samudra\Agent;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Обновляет установленный PHAR агента.
 */
final class SelfUpdater
{
    private readonly Client $httpClient;

    public function __construct(?Client $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => 300,
            'http_errors' => false,
        ]);
    }

    public function installedBinaryPath(): string
    {
        $pharPath = \Phar::running(false);
        if ($pharPath === '' || !is_file($pharPath)) {
            throw new RuntimeException('samudra self-update доступен только для установленного PHAR');
        }

        return $pharPath;
    }

    public function update(string $downloadUrl): string
    {
        $targetPath = $this->installedBinaryPath();
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir) || !is_writable($targetDir)) {
            throw new RuntimeException("Нет прав на запись в {$targetDir}");
        }

        $temporaryPath = tempnam($targetDir, 'samudra-update-');
        if ($temporaryPath === false) {
            throw new RuntimeException('Не удалось создать временный файл для обновления');
        }

        try {
            $response = $this->httpClient->request('GET', $downloadUrl, [
                'sink' => $temporaryPath,
                'headers' => ['Accept' => 'application/octet-stream'],
            ]);
        } catch (GuzzleException $e) {
            @unlink($temporaryPath);

            throw new RuntimeException('Не удалось скачать новый агент: ' . $e->getMessage(), 0, $e);
        }

        if ($response->getStatusCode() !== 200) {
            @unlink($temporaryPath);

            throw new RuntimeException('Не удалось скачать новый агент: HTTP ' . $response->getStatusCode());
        }

        $currentMode = @fileperms($targetPath);
        chmod($temporaryPath, is_int($currentMode) ? ($currentMode & 0o777) : 0o755);

        if (@rename($temporaryPath, $targetPath)) {
            return $targetPath;
        }

        if (!@copy($temporaryPath, $targetPath)) {
            @unlink($temporaryPath);

            throw new RuntimeException('Не удалось заменить текущий бинарь агента');
        }

        @unlink($temporaryPath);

        return $targetPath;
    }
}

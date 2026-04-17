<?php

declare(strict_types=1);

namespace Samudra\Agent;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Управление installation_id агента.
 */
final class InstallationManager
{
    private const string AGENT_DIR = '.samudra';
    private const string AGENT_FILE = 'agent.json';

    private string $agentDir;

    public function __construct(?string $agentDir = null)
    {
        $this->agentDir = $agentDir ?? getenv('HOME') . '/' . self::AGENT_DIR;
    }

    /**
     * Получает или создаёт installation_id.
     */
    public function getInstallationId(): string
    {
        $existingInstallationId = $this->currentInstallationId();
        if ($existingInstallationId !== null) {
            return $existingInstallationId;
        }

        // Создаём новый installation_id
        $installationId = $this->generateInstallationId();
        $this->saveInstallationId($installationId);

        return $installationId;
    }

    /**
     * Сбрасывает installation_id (для переустановки/клонирования).
     */
    public function resetInstallationId(): string
    {
        $installationId = $this->generateInstallationId();
        $this->saveInstallationId($installationId);

        return $installationId;
    }

    public function currentInstallationId(): ?string
    {
        $agentFile = $this->agentDir . '/' . self::AGENT_FILE;
        if (!is_file($agentFile)) {
            return null;
        }

        $data = json_decode(file_get_contents($agentFile), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            return null;
        }

        $installationId = $data['installation_id'] ?? null;
        if (!is_string($installationId) || trim($installationId) === '') {
            return null;
        }

        return $installationId;
    }

    private function generateInstallationId(): string
    {
        // UUID v4
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function saveInstallationId(string $installationId): void
    {
        $agentFile = $this->agentDir . '/' . self::AGENT_FILE;
        $dir = dirname($agentFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        $data = [
            'installation_id' => $installationId,
            'created_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];

        file_put_contents(
            $agentFile,
            json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        chmod($agentFile, 0o600);
    }
}

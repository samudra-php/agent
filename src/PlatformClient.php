<?php

declare(strict_types=1);

namespace Samudra\Agent;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use RuntimeException;

/**
 * Клиент для общения с Platform API.
 */
final class PlatformClient
{
    private readonly Client $httpClient;
    private string $baseUrl;
    private ?string $token = null;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->httpClient = new Client([
            'timeout' => 300,
            'http_errors' => false,
        ]);
    }

    public function setToken(string $token): void
    {
        $normalized = trim($token);
        if ($normalized === '') {
            throw new RuntimeException('Токен не может быть пустым');
        }

        $this->token = $normalized;
    }

    /**
     * Проверяет доступность платформы.
     *
     * @return array{status: string}
     */
    public function health(): array
    {
        [$statusCode, $data] = $this->requestJson('GET', '/api/v1/health');
        if ($statusCode !== 200) {
            throw new RuntimeException($this->buildApiError('Health check', $statusCode, $data));
        }

        return $data;
    }

    /**
     * Регистрирует проект по fingerprint.
     *
     * @return array{project_id: string, status: string}
     */
    public function registerProject(string $fingerprint): array
    {
        [$statusCode, $data] = $this->requestJson('POST', '/api/v1/projects/register', [
            'fingerprint' => $fingerprint,
        ]);
        if ($statusCode !== 200) {
            throw new RuntimeException($this->buildApiError('Project registration', $statusCode, $data));
        }

        return $data;
    }

    /**
     * Регистрирует installation для проекта.
     *
     * @return array{installation_id: string, status: string}
     */
    public function registerInstallation(
        string $installationId,
        string $projectId,
        ?string $hostname = null,
    ): array {
        [$statusCode, $data] = $this->requestJson('POST', '/api/v1/installations/register', [
            'installation_id' => $installationId,
            'project_id' => $projectId,
            'hostname' => $hostname,
        ]);
        if ($statusCode !== 200) {
            throw new RuntimeException($this->buildApiError('Installation registration', $statusCode, $data));
        }

        return $data;
    }

    /**
     * Отправляет bundle на ingest.
     *
     * @return array{run_id: string, status: string, bundle_id?: string}
     */
    public function uploadBundle(string $projectId, string $bundleJson): array
    {
        [$statusCode, $data] = $this->requestJson('POST', '/api/v1/bundles', [
            'project_id' => $projectId,
            'bundle' => json_decode($bundleJson, true, 512, JSON_THROW_ON_ERROR),
        ]);
        if ($statusCode !== 200) {
            throw new RuntimeException($this->buildApiError('Bundle upload', $statusCode, $data));
        }

        return $data;
    }

    /**
     * Проверяет, что токен принимается API.
     */
    public function assertTokenIsValid(): void
    {
        if ($this->token === null) {
            throw new RuntimeException('Токен не установлен');
        }

        [$statusCode, $data] = $this->requestJson('GET', '/api/v1/runs/' . $this->generateUuidV4());
        if (in_array($statusCode, [200, 404], true)) {
            return;
        }

        if (in_array($statusCode, [401, 403], true)) {
            throw new RuntimeException('Токен отклонён платформой (401/403)');
        }

        throw new RuntimeException($this->buildApiError('Token validation', $statusCode, $data));
    }

    /**
     * Возвращает статус run по run_id.
     *
     * @return array<string, mixed>
     */
    public function fetchRunStatus(string $runId): array
    {
        [$statusCode, $data] = $this->requestJson('GET', '/api/v1/runs/' . $runId);
        if ($statusCode !== 200) {
            throw new RuntimeException($this->buildApiError('Run status', $statusCode, $data));
        }

        return $data;
    }

    /**
     * @param array<string, mixed>|null $data
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function requestJson(string $method, string $path, ?array $data = null): array
    {
        $headers = ['Accept' => 'application/json'];
        $options = ['headers' => $headers];

        if ($data !== null) {
            $headers['Content-Type'] = 'application/json';
            $options['json'] = $data;
        }

        if ($this->token !== null) {
            $headers['Authorization'] = 'Bearer ' . $this->token;
        }

        $options['headers'] = $headers;

        try {
            $response = $this->httpClient->request($method, $this->baseUrl . $path, $options);
        } catch (RequestException $e) {
            throw new RuntimeException(
                'Platform request failed: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $statusCode = $response->getStatusCode();
        $body = trim((string) $response->getBody());
        if ($body === '') {
            return [$statusCode, []];
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                "Platform вернула невалидный JSON (HTTP {$statusCode})",
                0,
                $e,
            );
        }

        if (!is_array($decoded)) {
            throw new RuntimeException("Platform вернула невалидный JSON (HTTP {$statusCode})");
        }

        return [$statusCode, $decoded];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildApiError(string $operation, int $statusCode, array $data): string
    {
        $message = $data['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return "{$operation} failed: HTTP {$statusCode}: {$message}";
        }

        $errors = $data['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            $firstError = reset($errors);
            if (is_string($firstError) && $firstError !== '') {
                return "{$operation} failed: HTTP {$statusCode}: {$firstError}";
            }
        }

        return "{$operation} failed: HTTP {$statusCode}";
    }

    private function generateUuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0F | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3F | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

<?php

declare(strict_types=1);

namespace Samudra\Agent;

/**
 * Состояние совместимости текущего агента с платформой.
 */
readonly class AgentCompatibilityStatus
{
    public function __construct(
        public string $currentVersion,
        public ?string $minimumVersion,
        public ?string $recommendedVersion,
        public ?string $downloadUrl,
        public bool $isSupported,
        public bool $shouldUpdate,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromHealthPayload(array $payload): self
    {
        $agentPayload = $payload['agent'] ?? null;
        if (!is_array($agentPayload)) {
            return new self(
                currentVersion: AgentVersion::CURRENT,
                minimumVersion: null,
                recommendedVersion: null,
                downloadUrl: null,
                isSupported: true,
                shouldUpdate: false,
            );
        }

        $minimumVersion = self::readVersion($agentPayload, 'minimum_version');
        $recommendedVersion = self::readVersion($agentPayload, 'recommended_version');
        $downloadUrl = self::readString($agentPayload, 'download_url');

        return new self(
            currentVersion: AgentVersion::CURRENT,
            minimumVersion: $minimumVersion,
            recommendedVersion: $recommendedVersion,
            downloadUrl: $downloadUrl,
            isSupported: $minimumVersion === null || !AgentVersion::isCurrentLowerThan($minimumVersion),
            shouldUpdate: $recommendedVersion !== null && AgentVersion::isCurrentLowerThan($recommendedVersion),
        );
    }

    public function label(): string
    {
        return match (true) {
            !$this->isSupported => 'unsupported',
            $this->shouldUpdate => 'update_available',
            default => 'ok',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function readVersion(array $payload, string $key): ?string
    {
        $value = self::readString($payload, $key);
        if ($value === null) {
            return null;
        }

        return AgentVersion::normalize($value);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function readString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

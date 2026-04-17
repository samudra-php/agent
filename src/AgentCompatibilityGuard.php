<?php

declare(strict_types=1);

namespace Samudra\Agent;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Проверяет совместимость версии агента с платформой.
 */
final class AgentCompatibilityGuard
{
    /**
     * @param array<string, mixed>|null $healthPayload
     */
    public function resolve(PlatformClient $client, ?array $healthPayload = null): AgentCompatibilityStatus
    {
        return AgentCompatibilityStatus::fromHealthPayload($healthPayload ?? $client->health());
    }

    public function render(AgentCompatibilityStatus $status, SymfonyStyle $io): bool
    {
        if (!$status->isSupported) {
            $io->error($this->unsupportedLines($status));

            return false;
        }

        if ($status->shouldUpdate) {
            $io->warning($this->updateLines($status));
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function unsupportedLines(AgentCompatibilityStatus $status): array
    {
        $lines = [
            sprintf(
                'Текущая версия агента %s больше не поддерживается платформой.',
                $status->currentVersion,
            ),
        ];

        if ($status->minimumVersion !== null) {
            $lines[] = 'Минимально поддерживаемая версия: ' . $status->minimumVersion;
        }

        $lines[] = 'Обновите агент командой: samudra self-update';

        if ($status->downloadUrl !== null) {
            $lines[] = 'Или скачайте release вручную: ' . $status->downloadUrl;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function updateLines(AgentCompatibilityStatus $status): array
    {
        $lines = [
            sprintf(
                'Доступна более новая версия агента. Текущая: %s.',
                $status->currentVersion,
            ),
        ];

        if ($status->recommendedVersion !== null) {
            $lines[] = 'Рекомендуемая версия: ' . $status->recommendedVersion;
        }

        $lines[] = 'Чтобы обновиться, выполните: samudra self-update';

        return $lines;
    }
}

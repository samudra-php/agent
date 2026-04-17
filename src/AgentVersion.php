<?php

declare(strict_types=1);

namespace Samudra\Agent;

use RuntimeException;

/**
 * Текущая версия и release URL агента.
 */
final class AgentVersion
{
    public const string CURRENT = '0.1.1';
    public const string DEFAULT_RELEASE_URL = 'https://github.com/samudra-php/agent/releases/latest/download/samudra.phar';

    public static function normalize(string $version): string
    {
        $normalized = ltrim(trim($version), 'vV');
        if ($normalized === '') {
            throw new RuntimeException('Версия агента не может быть пустой');
        }

        return $normalized;
    }

    public static function releaseTag(string $version): string
    {
        return 'v' . self::normalize($version);
    }

    public static function releaseDownloadUrl(?string $version = null): string
    {
        if ($version === null) {
            return self::DEFAULT_RELEASE_URL;
        }

        return sprintf(
            'https://github.com/samudra-php/agent/releases/download/%s/samudra.phar',
            self::releaseTag($version),
        );
    }

    public static function isCurrentLowerThan(string $version): bool
    {
        return version_compare(self::CURRENT, self::normalize($version), '<');
    }

    public static function isCurrent(string $version): bool
    {
        return version_compare(self::CURRENT, self::normalize($version), '==');
    }
}

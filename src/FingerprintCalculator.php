<?php

declare(strict_types=1);

namespace Samudra\Agent;

/**
 * Вычисляет fingerprint проекта для регистрации.
 */
final class FingerprintCalculator
{
    /**
     * Вычисляет fingerprint проекта.
     *
     * Primary: sha256(normalized_git_remote + composer_name + repo_root_hint)
     * Fallback: sha256(composer_name + package_roots)
     */
    public function calculate(string $projectRoot): string
    {
        $parts = [];

        // Пытаемся получить git remote
        $gitRemote = $this->getGitRemote($projectRoot);
        if ($gitRemote !== null) {
            $parts[] = $this->normalizeGitRemote($gitRemote);
        }

        // Пытаемся получить composer.json name
        $composerName = $this->getComposerName($projectRoot);
        if ($composerName !== null) {
            $parts[] = $composerName;
        }

        // Repo root hint
        $repoRootHint = basename($projectRoot);
        $parts[] = $repoRootHint;

        $raw = implode('|', array_filter($parts));

        return hash('sha256', $raw);
    }

    private function getGitRemote(string $projectRoot): ?string
    {
        $gitDir = $projectRoot . '/.git';
        if (!is_dir($gitDir) && !is_file($gitDir)) {
            return null;
        }

        $output = [];
        $result = null;
        exec("cd {$projectRoot} && git remote get-url origin 2>/dev/null", $output, $result);

        if ($result === 0 && $output !== []) {
            return trim($output[0]);
        }

        return null;
    }

    /**
     * Нормализует git remote к canonical form.
     */
    private function normalizeGitRemote(string $remote): string
    {
        // Убираем trailing slash и .git
        $remote = rtrim($remote, '/');
        $remote = preg_replace('/\.git$/', '', $remote) ?? $remote;

        // SCP-like SSH: git@host:org/repo -> ssh://git@host/org/repo
        if (preg_match('/^git@([^:]+):(.+)$/', $remote, $matches)) {
            $remote = 'ssh://git@' . $matches[1] . '/' . $matches[2];
        }

        // Приводим scheme и host к lowercase
        $remote = strtolower($remote);

        // Убираем дублирующиеся /
        $remote = preg_replace('#/+#', '/', $remote) ?? $remote;

        return $remote;
    }

    private function getComposerName(string $projectRoot): ?string
    {
        $composerFile = $projectRoot . '/composer.json';
        if (!is_file($composerFile)) {
            return null;
        }

        $content = file_get_contents($composerFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $data['name'] ?? null;
    }
}

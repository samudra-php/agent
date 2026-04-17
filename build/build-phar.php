<?php

declare(strict_types=1);

if (ini_get('phar.readonly') === '1') {
    fwrite(STDERR, "phar.readonly=1. Run with: php -d phar.readonly=0 build/build-phar.php\n");
    exit(1);
}

$agentRoot = realpath(__DIR__ . '/..');
if ($agentRoot === false) {
    fwrite(STDERR, "Failed to resolve samudra-agent root.\n");
    exit(1);
}

$distDir = $agentRoot . '/dist';
$buildRoot = $agentRoot . '/.build/phar-root';
$pharPath = $distDir . '/samudra.phar';

recreateDirectory($distDir);
recreateDirectory(dirname($buildRoot));
recreateDirectory($buildRoot);

copyPath($agentRoot . '/composer.json', $buildRoot . '/composer.json');
copyPath($agentRoot . '/composer.lock', $buildRoot . '/composer.lock');
copyPath($agentRoot . '/src', $buildRoot . '/src');
copyPath($agentRoot . '/vendor', $buildRoot . '/vendor');

$phar = new Phar($pharPath, 0, 'samudra.phar');
$phar->startBuffering();
$phar->buildFromDirectory($buildRoot);
$phar->setStub((string) file_get_contents(__DIR__ . '/phar-stub.php'));
$phar->stopBuffering();

chmod($pharPath, 0755);

fwrite(STDOUT, "Built {$pharPath}\n");

/**
 * @throws RuntimeException
 */
function recreateDirectory(string $path): void
{
    if (is_dir($path)) {
        removeDirectory($path);
    }

    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("Failed to create directory: {$path}");
    }
}

/**
 * @throws RuntimeException
 */
function copyPath(string $source, string $destination): void
{
    if (is_link($source)) {
        $resolvedSource = realpath($source);
        if ($resolvedSource === false) {
            throw new RuntimeException("Failed to resolve symlink: {$source}");
        }

        copyPath($resolvedSource, $destination);

        return;
    }

    if (is_file($source)) {
        $targetDir = dirname($destination);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            throw new RuntimeException("Failed to create directory: {$targetDir}");
        }

        if (!copy($source, $destination)) {
            throw new RuntimeException("Failed to copy file {$source} -> {$destination}");
        }

        return;
    }

    if (!is_dir($source)) {
        throw new RuntimeException("Source path does not exist: {$source}");
    }

    if (!is_dir($destination) && !mkdir($destination, 0777, true) && !is_dir($destination)) {
        throw new RuntimeException("Failed to create directory: {$destination}");
    }

    $iterator = new FilesystemIterator($source, FilesystemIterator::SKIP_DOTS);

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        copyPath($item->getPathname(), $destination . '/' . $item->getBasename());
    }
}

/**
 * @throws RuntimeException
 */
function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        $itemPath = $item->getPathname();

        if ($item->isDir() && !$item->isLink()) {
            removeDirectory($itemPath);
            continue;
        }

        if (!unlink($itemPath)) {
            throw new RuntimeException("Failed to remove file: {$itemPath}");
        }
    }

    if (!rmdir($path)) {
        throw new RuntimeException("Failed to remove directory: {$path}");
    }
}

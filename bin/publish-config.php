#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Copy this package's config/*.php stubs into the root project's config/
 * only when the destination file does not already exist (never overwrite).
 *
 * Invoked by the root project's Composer post-autoload-dump (or
 * `composer run-script publish-config` when this package is the root).
 */
$packageRoot = dirname(__DIR__);
$stubsDir = $packageRoot . DIRECTORY_SEPARATOR . 'config';

if (!is_dir($stubsDir)) {
    exit(0);
}

$projectRoot = resolveProjectRoot($packageRoot);
if ($projectRoot === null) {
    fwrite(STDERR, 'loongs publish-config: cannot resolve project root from ' . $packageRoot . PHP_EOL);
    exit(0);
}

$configDir = $projectRoot . DIRECTORY_SEPARATOR . 'config';
if (!is_dir($configDir) && !@mkdir($configDir, 0775, true) && !is_dir($configDir)) {
    fwrite(STDERR, 'loongs publish-config: cannot create ' . $configDir . PHP_EOL);
    exit(1);
}

$packageName = packageName($packageRoot);
$wrote = 0;

foreach (glob($stubsDir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $src) {
    $base = basename($src);
    $dest = $configDir . DIRECTORY_SEPARATOR . $base;
    if (is_file($dest)) {
        continue;
    }
    if (!@copy($src, $dest)) {
        fwrite(STDERR, "loongs publish-config: failed to write config/{$base}" . PHP_EOL);
        exit(1);
    }
    echo "loongs publish-config: wrote config/{$base}";
    if ($packageName !== null) {
        echo " ({$packageName})";
    }
    echo PHP_EOL;
    $wrote++;
}

exit(0);

/**
 * Prefer Composer cwd (project root). Fall back to walking up from the package
 * until a directory containing both composer.json and vendor/ is found.
 */
function resolveProjectRoot(string $packageRoot): ?string
{
    $cwd = getcwd();
    if (is_string($cwd) && $cwd !== '' && isProjectRoot($cwd)) {
        // When developing the package itself as root, do not copy into the package.
        $realCwd = realpath($cwd) ?: $cwd;
        $realPkg = realpath($packageRoot) ?: $packageRoot;
        if ($realCwd === $realPkg) {
            return null;
        }
        return $realCwd;
    }

    $dir = $packageRoot;
    for ($i = 0; $i < 8; $i++) {
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        if (isProjectRoot($parent)) {
            $real = realpath($parent);
            return $real !== false ? $real : $parent;
        }
        $dir = $parent;
    }

    return null;
}

function isProjectRoot(string $dir): bool
{
    return is_file($dir . DIRECTORY_SEPARATOR . 'composer.json')
        && is_dir($dir . DIRECTORY_SEPARATOR . 'vendor');
}

function packageName(string $packageRoot): ?string
{
    $file = $packageRoot . DIRECTORY_SEPARATOR . 'composer.json';
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || !isset($data['name']) || !is_string($data['name'])) {
        return null;
    }
    return $data['name'];
}

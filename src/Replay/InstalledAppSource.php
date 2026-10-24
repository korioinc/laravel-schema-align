<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Replay;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Env;
use RuntimeException;

/** Identifies installed migration inputs without copying or rewriting application files. */
final class InstalledAppSource
{
    /**
     * @param  list<mixed>  $additionalPaths  Unvalidated application configuration.
     * @return array{root: string, autoload: string, revision: string, expected_migrations: array<string, string>, source: array<string, mixed>}
     */
    public function inspect(Application $app, array $additionalPaths): array
    {
        $root = realpath($app->basePath());

        if ($root === false || ! is_file($root.'/bootstrap/app.php') || ! is_file($root.'/composer.lock')) {
            throw new RuntimeException('Replay requires an installed Laravel application with composer.lock.');
        }
        $root = str_replace('\\', '/', $root);
        $autoload = self::autoload($root);
        $manifest = self::manifest(self::paths($app, $additionalPaths));
        $lock = file_get_contents($root.'/composer.lock');

        if ($lock === false) {
            throw new RuntimeException('Cannot read installed composer.lock.');
        }

        return ['root' => $root, 'autoload' => $autoload, 'revision' => self::revision($lock, $manifest),
            'expected_migrations' => $manifest,
            'source' => ['builder' => 'installed-app', 'mode' => 'in-place', 'fingerprint' => 'migrations-and-lock',
                'runtime' => PHP_VERSION.'|'.PHP_OS_FAMILY.'|'.PHP_INT_SIZE],
        ];
    }

    public static function autoload(string $root): string
    {
        $bytes = file_get_contents($root.'/composer.json');
        $composer = $bytes === false ? null : json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($composer)) {
            throw new RuntimeException('Cannot read installed Composer configuration.');
        }
        $vendor = Env::get('COMPOSER_VENDOR_DIR') ?: ($composer['config']['vendor-dir'] ?? 'vendor');

        if (! is_string($vendor) || $vendor === '') {
            throw new RuntimeException('Invalid installed Composer vendor directory.');
        }
        $vendor = str_replace('\\', '/', $vendor);

        if (! str_starts_with($vendor, '/') && preg_match('~\A[A-Za-z]:/~', $vendor) !== 1) {
            $vendor = $root.'/'.$vendor;
        }
        $autoload = realpath($vendor.'/autoload.php');

        if ($autoload === false || ! is_file($autoload)) {
            throw new RuntimeException('Replay requires the installed Composer autoloader.');
        }

        return str_replace('\\', '/', $autoload);
    }

    /** @param list<mixed> $additionalPaths Unvalidated application configuration.
     * @return list<string>
     */
    public static function paths(Application $app, array $additionalPaths): array
    {
        $paths = [$app->databasePath('migrations'), ...$app->make('migrator')->paths()];
        foreach ($additionalPaths as $path) {
            if (! is_string($path) || ! preg_match('~\A[a-zA-Z0-9_./-]+\z~', $path) || str_starts_with($path, '/')
                || in_array('..', explode('/', $path), true)) {
                throw new RuntimeException('Invalid app-relative migration path.');
            }
            $paths[] = $app->basePath($path);
        }
        $resolved = [];
        foreach ($paths as $path) {
            $real = realpath($path);

            if ($real === false || (! is_dir($real) && (! is_file($real) || ! str_ends_with($path, '.php')))) {
                throw new RuntimeException('Installed migration path is missing or invalid: '.$path);
            }
            // Keep a file alias's migration name; directories may be installed package symlinks.
            $resolved[] = str_replace('\\', '/', is_dir($real) ? $real : realpath(dirname($path)).'/'.basename($path));
        }
        $resolved = array_values(array_unique($resolved));
        sort($resolved, SORT_STRING);

        return $resolved;
    }

    /** @param list<string> $paths
     * @return array<string, string>
     */
    public static function manifest(array $paths): array
    {
        $manifest = [];
        $names = [];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $manifest[rtrim($path, '/').'/'] = hash('sha256', 'directory');
                $files = glob($path.'/*_*.php');

                if ($files === false || ! is_readable($path)) {
                    throw new RuntimeException('Cannot enumerate installed migrations: '.$path);
                }
            } else {
                $files = [$path];
            }
            foreach ($files as $file) {
                $file = str_replace('\\', '/', $file);
                $name = pathinfo($file, PATHINFO_FILENAME);

                if (isset($names[$name]) && $names[$name] !== $file) {
                    throw new RuntimeException('Duplicate installed migration name: '.$name);
                }
                $names[$name] = $file;
                $digest = is_file($file) ? hash_file('sha256', $file) : false;

                if ($digest === false) {
                    throw new RuntimeException('Cannot read installed migration: '.$file);
                }
                $manifest[$file] = $digest;
            }
        }
        ksort($manifest, SORT_STRING);

        return $manifest;
    }

    /** @param array<string, string> $manifest */
    public static function revision(string $lock, array $manifest): string
    {
        ksort($manifest, SORT_STRING);

        return hash('sha256', json_encode([PHP_VERSION, PHP_OS_FAMILY, PHP_INT_SIZE, hash('sha256', $lock), $manifest], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}

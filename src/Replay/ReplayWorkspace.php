<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Replay;

use Illuminate\Filesystem\Filesystem;
use Korioinc\SchemaAlign\Filesystem\WindowsDirectoryPrivacy;
use RuntimeException;
use Throwable;

/** Owns only worker cache, environment and storage files, never application source. */
final class ReplayWorkspace
{
    public readonly string $path;

    public function __construct()
    {
        $directory = sys_get_temp_dir().'/schema-align-runtime-'.bin2hex(random_bytes(16));

        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create private replay runtime directory.');
        }
        $resolved = realpath($directory);

        if ($resolved === false) {
            rmdir($directory);

            throw new RuntimeException('Cannot resolve private replay runtime directory.');
        }
        $this->path = str_replace('\\', '/', $resolved);

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                WindowsDirectoryPrivacy::assertPrivate($this->path, inheritanceRequired: true);
            } elseif (! chmod($this->path, 0700)) {
                throw new RuntimeException('Cannot protect replay runtime directory permissions.');
            }
        } catch (Throwable $error) {
            $this->remove();

            throw $error;
        }
    }

    public function remove(): void
    {
        clearstatcache(true, $this->path);

        if (is_link($this->path) || (file_exists($this->path) && ! is_dir($this->path))) {
            throw new RuntimeException('Replay runtime directory was replaced; refusing cleanup.');
        }

        if (is_dir($this->path) && ! (new Filesystem)->deleteDirectory($this->path)) {
            throw new RuntimeException('Cannot remove private replay runtime directory.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Korioinc\SchemaAlign\Planning;

use Korioinc\SchemaAlign\Filesystem\WindowsDirectoryPrivacy;
use Korioinc\SchemaAlign\Schema\Snapshot;
use RuntimeException;

final class PlanStore
{
    public function __construct(private readonly string $directory) {}

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(array $payload): array
    {
        $this->prepare();
        // Older readers must reject new projection rules and destructive execution permissions.
        $payload['format'] = Operation::planFormat($payload['operations'] ?? []);
        $payload['id'] = bin2hex(random_bytes(16));
        $payload['created_at'] = gmdate(DATE_ATOM);
        $payload['signature'] = hash_hmac('sha256', Snapshot::canonical($payload), $this->key());
        $this->atomic($this->path($payload['id'], 'plan'), $payload);
        $this->record($payload['id'], ['status' => 'planned']);
        $pointer = ['format' => 1, 'purpose' => 'latest-plan', 'id' => $payload['id']];
        $pointer['signature'] = hash_hmac('sha256', Snapshot::canonical($pointer), $this->key(false));
        $this->atomic($this->directory.DIRECTORY_SEPARATOR.'.latest.json', $pointer);

        return $payload;
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        if (! $this->existing()) {
            return null;
        }
        $path = $this->directory.DIRECTORY_SEPARATOR.'.latest.json';

        // A dangling symlink is corrupt publication, not an unpublished store.
        if (! is_link($path) && ! file_exists($path)) {
            return null;
        }

        $pointer = $this->read($path);
        $signature = $pointer['signature'] ?? null;
        unset($pointer['signature']);

        if (! is_string($signature) || ! hash_equals(hash_hmac('sha256', Snapshot::canonical($pointer), $this->key(false)), $signature)) {
            throw new RuntimeException('Latest plan pointer signature mismatch. Issue a new plan.');
        }

        if (count($pointer) !== 3 || ($pointer['format'] ?? null) !== 1 || ($pointer['purpose'] ?? null) !== 'latest-plan' || ! is_string($pointer['id'] ?? null)) {
            throw new RuntimeException('Invalid latest plan pointer identity or version.');
        }

        return $this->load($pointer['id']);
    }

    /** @return array<string, mixed> */
    public function load(string $id): array
    {
        if (! $this->existing()) {
            throw new RuntimeException('Plan or execution status not found.');
        }
        $plan = $this->read($this->path($id, 'plan'));
        $signature = $plan['signature'] ?? null;
        unset($plan['signature']);

        if (! is_string($signature) || ! hash_equals(hash_hmac('sha256', Snapshot::canonical($plan), $this->key(false)), $signature)) {
            throw new RuntimeException('Plan signature mismatch. Review and issue a new plan; never edit plan SQL.');
        }

        if (($plan['id'] ?? null) !== $id || ! in_array($plan['format'] ?? null, [1, 2, 3], true)
            || $plan['format'] < Operation::planFormat($plan['operations'] ?? [])) {
            throw new RuntimeException('Invalid plan identity or version.');
        }
        $plan['signature'] = $signature;

        return $plan;
    }

    /** @return array<string, mixed> */
    public function status(string $id): array
    {
        if (! $this->existing()) {
            throw new RuntimeException('Plan or execution status not found.');
        }

        return $this->read($this->path($id, 'status'));
    }

    /** @param array<string, mixed> $state */
    public function record(string $id, array $state): void
    {
        $this->prepare();
        $state['updated_at'] = gmdate(DATE_ATOM);
        $this->atomic($this->path($id, 'status'), $state);
    }

    /** @return resource */
    public function lock(string $id): mixed
    {
        $this->prepare();
        $path = $this->path($id, 'lock');

        if (is_link($path)) {
            throw new RuntimeException('Plan lock must not be a symlink.');
        }
        $handle = fopen($path, 'c');

        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('This plan is already being applied.');
        }
        chmod($path, 0600);

        return $handle;
    }

    private function existing(): bool
    {
        if (is_link($this->directory)) {
            throw new RuntimeException('Plan directory must not be a symlink.');
        }

        if (! file_exists($this->directory)) {
            return false;
        }

        if (! is_dir($this->directory)) {
            throw new RuntimeException('Invalid plan directory.');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            WindowsDirectoryPrivacy::assertPrivate($this->directory);
        } else {
            $permissions = fileperms($this->directory);

            if ($permissions === false || ($permissions & 0077) !== 0) {
                throw new RuntimeException('Private plan directory permissions are required.');
            }
        }

        return true;
    }

    private function prepare(): void
    {
        if (! is_dir($this->directory)) {
            @mkdir($this->directory, 0700, true);
            clearstatcache(true, $this->directory);
        }

        if (is_link($this->directory) || ! is_dir($this->directory)) {
            throw new RuntimeException('Cannot create private plan directory.');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            WindowsDirectoryPrivacy::assertPrivate($this->directory);
        } elseif (! chmod($this->directory, 0700)) {
            throw new RuntimeException('Cannot protect plan directory permissions.');
        }
    }

    private function path(string $id, string $suffix): string
    {
        if (! preg_match('/\A[a-f0-9]{32}\z/D', $id)) {
            throw new RuntimeException('Invalid plan ID.');
        }

        return $this->directory.DIRECTORY_SEPARATOR.$id.'.'.$suffix.'.json';
    }

    private function key(bool $create = true): string
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.'.key';
        $lockPath = $path.'.lock';

        if (is_link($lockPath)) {
            throw new RuntimeException('Signing key lock must not be a symlink.');
        }
        $lock = fopen($lockPath, 'c');

        if ($lock === false) {
            throw new RuntimeException('Cannot lock signing key.');
        }

        // A separate, stable lock covers creation and every read. Locking only
        // the key leaves a gap between its exclusive creation and the lock.
        try {
            if (! chmod($lockPath, 0600) || ! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock signing key.');
            }
            clearstatcache(true, $path);

            if (is_link($path)) {
                throw new RuntimeException('Signing key must not be a symlink.');
            }

            if (! is_file($path)) {
                if (! $create) {
                    throw new RuntimeException('Signing key unavailable.');
                }
                $handle = fopen($path, 'x');

                if ($handle === false) {
                    throw new RuntimeException('Cannot create signing key.');
                }

                try {
                    $key = bin2hex(random_bytes(32));

                    if (! chmod($path, 0600) || fwrite($handle, $key) !== strlen($key) || ! fflush($handle) || ! fsync($handle)) {
                        throw new RuntimeException('Cannot durably write signing key.');
                    }
                } finally {
                    fclose($handle);
                }
            }
            $key = file_get_contents($path);

            if ($key === false || ! preg_match('/\A[a-f0-9]{64}\z/D', $key)) {
                throw new RuntimeException('Signing key unavailable.');
            }

            return $key;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('Plan or execution status not found.');
        }
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Cannot read plan file.');
        }
        $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException('Invalid plan document.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function atomic(string $path, array $data): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Plan files must not be symlinks.');
        }
        $temp = $path.'.'.bin2hex(random_bytes(8));
        $handle = fopen($temp, 'x');

        if ($handle === false) {
            throw new RuntimeException('Cannot persist plan state.');
        }

        try {
            chmod($temp, 0600);
            $bytes = Snapshot::canonical($data);

            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Cannot durably write plan state.');
            }
        } finally {
            fclose($handle);
        }

        if (! rename($temp, $path)) {
            @unlink($temp);

            throw new RuntimeException('Cannot atomically persist plan state.');
        }
    }
}

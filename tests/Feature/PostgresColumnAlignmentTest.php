<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\Tests\Support\ApplyFixture;

it('aligns reviewed identifier and timestamp widening and approved defaulted column removal without losing surviving data', function (string $scenario): void {
    if (! getenv('SCHEMA_ALIGN_TEST_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN for isolated PostgreSQL alignment tests.');
    }
    $f = new ApplyFixture($this->app);
    $table = $f->quotedSchema.'.tokens';

    try {
        $f->pdo->exec('CREATE TABLE '.$table.' (app_type varchar(255) NOT NULL, fid varchar(255) NOT NULL, synced_at timestamp(3), created_at timestamp(3), updated_at timestamp(3), next_sync_at timestamp(3), CONSTRAINT tokens_unique UNIQUE (app_type, fid))');
        $f->pdo->exec('CREATE INDEX tokens_due ON '.$table.' (next_sync_at)');
        $catalog = new PostgresCatalog($f->pdo, [$f->schema]);
        $desired = $catalog->inspect();
        $f->pdo->exec('ALTER TABLE '.$table.' ALTER COLUMN fid TYPE char(22), ALTER COLUMN synced_at TYPE timestamp(0), ALTER COLUMN created_at TYPE timestamp(0), ALTER COLUMN updated_at TYPE timestamp(0), ALTER COLUMN next_sync_at TYPE timestamp(0), ADD COLUMN revision bigint NOT NULL DEFAULT 1');
        $fid = $scenario === 'padded' ? 'short' : 'abcdefghijklmnopqrstuv';
        $f->pdo->exec('INSERT INTO '.$table." (app_type, fid, synced_at, revision) VALUES ('app', ".$f->pdo->quote($fid).", '2026-01-01 12:34:56', 7)");
        $actual = $catalog->inspect();
        $comparison = (new SchemaComparator)->compare($desired, $actual);
        $plan = $f->align->pin([...$comparison, 'actual' => $actual->toArray(), 'desired' => $desired->toArray(),
            'source' => ['revision' => 'isolated-type-alignment'],
            'binding' => TargetConnection::binding($this->app->make('config')->get('database.connections.schema_align_testing'), 'isolated-tests', $this->app->environment())], ['all-supported']);
        $before = $f->fingerprint();
        expect(fn () => $f->align->apply($plan['id'], $plan['signature']))->toThrow(RuntimeException::class)
            ->and($f->fingerprint())->toBe($before)
            ->and($f->pdo->query('SELECT revision FROM '.$table)->fetchColumn())->toBe(7);

        if ($scenario === 'rollback' || $scenario === 'postcondition') {
            $plan['operations'][array_key_last($plan['operations'])]['sql'][] = $scenario === 'rollback'
                ? 'SELECT 1 / 0'
                : 'ALTER TABLE '.$table.' ADD COLUMN unexpected text';
            unset($plan['signature']);
            $plan = $f->align->store()->save($plan);
        }

        if ($scenario !== 'success') {
            expect(fn () => $f->align->apply($plan['id'], $plan['signature'], allowDropColumns: true))->toThrow($scenario === 'postcondition' ? RuntimeException::class : PDOException::class)
                ->and($f->fingerprint())->toBe($before)
                ->and($f->pdo->query('SELECT revision FROM '.$table)->fetchColumn())->toBe(7);

            return;
        }
        $f->align->apply($plan['id'], $plan['signature'], allowDropColumns: true);
        $f->align->apply($plan['id'], $plan['signature']);
        expect($f->fingerprint())->toBe($desired->fingerprint())
            ->and($f->pdo->query('SELECT fid FROM '.$table)->fetchColumn())->toBe($fid)
            ->and($f->pdo->query('SELECT synced_at::text FROM '.$table)->fetchColumn())->toBe('2026-01-01 12:34:56');
        expect(fn () => $f->pdo->exec('INSERT INTO '.$table." (app_type, fid) VALUES ('app', ".$f->pdo->quote($fid).')'))->toThrow(PDOException::class);
        $f->pdo->exec('ALTER TABLE '.$table.' ADD COLUMN revision text');
        $f->pdo->exec('UPDATE '.$table." SET revision = 'Reused after approval'");
        expect($f->pdo->query('SELECT revision FROM '.$table)->fetchColumn())->toBe('Reused after approval');
    } finally {
        $f->close();
    }
})->with(['success', 'padded', 'rollback', 'postcondition']);

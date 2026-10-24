<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Tests\Support\InstalledAppFixture;

it('compares schemas whose names match Laravel catalog aliases', function (string $schema, string $command): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        $fixture->pdo->exec('ALTER SCHEMA '.$fixture->quotedSchema.' RENAME TO "'.$schema.'"');

        try {
            $config = $fixture->app->make('config');
            $config->set('database.connections.installed_target.search_path', $schema);
            $config->set('schema-align.schemas', [$schema]);
            $result = $command === 'diff' ? $fixture->align->diff() : $fixture->align->plan(['all-safe']);

            if ($command === 'diff') {
                expect($result['desired']['tables'][$schema.'.sample_records']['columns'])->toHaveKey('optional_label');
            } else {
                expect($result)->toHaveKeys(['id', 'signature']);
            }
            expect($fixture->pdo->query('SELECT name FROM "'.$schema.'".sample_records WHERE id = 1')->fetchColumn())->toBe('Sample record A');
        } finally {
            $fixture->pdo->exec('ALTER SCHEMA "'.$schema.'" RENAME TO '.$fixture->quotedSchema);
        }
    } finally {
        $fixture->close();
    }
})->with([['c', 'diff'], ['n', 'plan']]);

it('replays installed application source with common configured credential values', function (string $secret): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        $fixture->app->make('config')->set('fixture-settings.password', $secret);
        $before = $fixture->fingerprint();
        $comparison = $fixture->align->diff();

        expect($comparison['desired']['tables'])->toHaveKey($fixture->schema.'.sample_records')
            ->and($fixture->fingerprint())->toBe($before)
            ->and($fixture->recordName())->toBe('Sample record A');
    } finally {
        $fixture->close();
    }
})->with(['password', 'unused']);

it('preserves original comments when superuser replay targets an implicit system catalog', function (bool $createTable): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1' || getenv('SCHEMA_ALIGN_SUPERUSER_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable PostgreSQL administrator tests are required.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        $config = $fixture->app->make('config');
        $config->set('database.connections.installed_target.username', getenv('SCHEMA_ALIGN_TEST_ADMIN_USER'));
        $config->set('database.connections.installed_target.password', getenv('SCHEMA_ALIGN_TEST_ADMIN_PASSWORD'));
        $fixture->pdo->exec('COMMENT ON SCHEMA '.$fixture->quotedSchema." IS 'Original comment'");
        $sql = "UPDATE pg_description SET description = 'Changed by replay' WHERE objoid = (SELECT oid FROM pg_namespace WHERE nspname = ".$fixture->pdo->quote($fixture->schema).") AND classoid = 'pg_namespace'::regclass";

        if ($createTable) {
            $sql = 'CREATE TEMP TABLE probe AS WITH changed AS ('.$sql.' RETURNING description) SELECT * FROM changed';
        }
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_catalog_write.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');
        $failure = null;

        try {
            $fixture->align->diff();
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        expect($fixture->pdo->query("SELECT obj_description(oid, 'pg_namespace') FROM pg_namespace WHERE nspname = ".$fixture->pdo->quote($fixture->schema))->fetchColumn())->toBe('Original comment')
            ->and($failure?->getMessage())->toContain('replay DML');
    } finally {
        $fixture->close();
    }
})->with([false, true]);

it('preserves rows outside the comparison scope when a migration tries to write them', function (string $operation, string $command): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);
    $outside = '"'.$fixture->schema.'_outside"';

    try {
        $fixture->pdo->exec('CREATE SCHEMA '.$outside);
        $fixture->pdo->exec('CREATE TABLE '.$outside.'.records (id integer, note text)');
        $fixture->pdo->exec("INSERT INTO {$outside}.records VALUES (1, 'Original row')");
        $sql = match ($operation) {
            'update' => "UPDATE {$outside}.records SET note = 'Changed by replay'",
            'insert' => "INSERT INTO {$outside}.records VALUES (2, 'Inserted by replay')",
            'delete' => "DELETE FROM {$outside}.records",
            'cte' => "WITH changed AS (UPDATE {$outside}.records SET note = 'Changed by CTE' RETURNING id) SELECT * FROM changed",
        };
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_external_write.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');
        $before = $fixture->fingerprint();
        $failure = null;

        try {
            $command === 'diff' ? $fixture->align->diff() : $fixture->align->plan(['all-safe']);
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        expect($fixture->pdo->query('SELECT id, note FROM '.$outside.'.records ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))
            ->toBe([['id' => 1, 'note' => 'Original row']])
            ->and($fixture->fingerprint())->toBe($before)
            ->and($fixture->recordName())->toBe('Sample record A')
            ->and($failure?->getMessage())->toContain('replay DML');
    } finally {
        $fixture->pdo->exec('DROP SCHEMA IF EXISTS '.$outside.' CASCADE');
        $fixture->close();
    }
})->with([['update', 'diff'], ['insert', 'plan'], ['delete', 'diff'], ['cte', 'plan']]);

it('preserves original sequence state when a migration calls a sequence mutation function', function (string $function, string $command): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        $sequence = $fixture->quotedSchema.'.migrations_id_seq';
        $before = $fixture->pdo->query('SELECT last_value, is_called FROM '.$sequence)->fetch(PDO::FETCH_ASSOC);
        $sql = 'SELECT pg_catalog.'.$function.'('.$fixture->pdo->quote($sequence).'::regclass'.($function === 'setval' ? ', 1000' : '').')';
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_sequence_write.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::select('.var_export($sql, true).'); } };');
        $failure = null;

        try {
            $command === 'diff' ? $fixture->align->diff() : $fixture->align->plan(['all-safe']);
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        expect($fixture->pdo->query('SELECT last_value, is_called FROM '.$sequence)->fetch(PDO::FETCH_ASSOC))->toBe($before)
            ->and($failure?->getMessage())->toContain('sequence');
    } finally {
        $fixture->close();
    }
})->with([['setval', 'diff'], ['nextval', 'plan']]);

it('preserves original data when an operator expression tries to redirect subsequent replay writes', function (string $command): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        $rowsQuery = 'SELECT id, name FROM '.$fixture->quotedSchema.'.sample_records ORDER BY id';
        $ledgerQuery = 'SELECT migration, batch FROM '.$fixture->quotedSchema.'.migrations ORDER BY id';
        $rows = $fixture->pdo->query($rowsQuery)->fetchAll(PDO::FETCH_ASSOC);
        $ledger = $fixture->pdo->query($ledgerQuery)->fetchAll(PDO::FETCH_ASSOC);
        $sql = "SELECT '' OPERATOR(pg_catalog.||) set_config('search_path', ".$fixture->pdo->quote($fixture->schema).', false)';
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_operator_scope.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::select('.var_export($sql, true).'); \\Illuminate\\Support\\Facades\\DB::delete("DELETE FROM sample_records"); } };');
        $failure = null;

        try {
            $command === 'diff' ? $fixture->align->diff() : $fixture->align->plan(['all-safe']);
        } catch (RuntimeException $error) {
            $failure = $error;
        }
        expect($fixture->pdo->query($rowsQuery)->fetchAll(PDO::FETCH_ASSOC))->toBe($rows)
            ->and($fixture->pdo->query($ledgerQuery)->fetchAll(PDO::FETCH_ASSOC))->toBe($ledger)
            ->and($failure)->toBeInstanceOf(RuntimeException::class);
    } finally {
        $fixture->close();
    }
})->with(['diff', 'plan']);

it('replays local DML and writable CTEs with serial defaults in owned tables', function (): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_local_write.php', <<<'MIGRATION'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\DB::statement('CREATE TABLE replay_records (id serial PRIMARY KEY, note text)');
        \Illuminate\Support\Facades\DB::statement("INSERT INTO replay_records (note) VALUES ('Initial')");
        \Illuminate\Support\Facades\DB::statement("WITH changed AS (UPDATE ONLY (replay_records) AS r SET note = 'Updated' RETURNING id) INSERT INTO replay_records (note) SELECT 'Inserted' FROM changed");
        \Illuminate\Support\Facades\DB::statement('DELETE FROM ONLY replay_records WHERE id = 1');
        $row = \Illuminate\Support\Facades\DB::selectOne('SELECT id, note FROM replay_records');
        if ($row->id !== 2 || $row->note !== 'Inserted') {
            throw new RuntimeException('Local replay DML did not run.');
        }
        \Illuminate\Support\Facades\DB::select('SELECT 1 AS update, 2 AS insert, 3 AS delete, 4 AS replace, 5 AS merge');
        \Illuminate\Support\Facades\DB::select('WITH update AS (SELECT 1 AS id) SELECT * FROM update');
        \Illuminate\Support\Facades\DB::select('SELECT * FROM (VALUES (1)) AS nextval(id)');
        \Illuminate\Support\Facades\DB::select('WITH setval(id) AS (SELECT 1) SELECT * FROM setval');
        \Illuminate\Support\Facades\DB::select('SELECT (update) FROM (SELECT 1 AS update) t');
    }
};
MIGRATION);
        $before = $fixture->fingerprint();
        $comparison = $fixture->align->diff();

        expect($comparison['desired']['tables'])->toHaveKey($fixture->schema.'.replay_records')
            ->and($fixture->fingerprint())->toBe($before)
            ->and($fixture->recordName())->toBe('Sample record A');
    } finally {
        $fixture->close();
    }
});

it('cannot produce an executable plan from a partially failed PostgreSQL baseline', function (): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with a disposable PostgreSQL target.');
    }
    $fixture = new InstalledAppFixture($this->app);

    try {
        $before = $fixture->fingerprint();
        file_put_contents($fixture->directory.'/database/migrations/2026_01_05_000000_fail.php', <<<'MIGRATION'
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('partial_replay', function ($table): void { $table->bigInteger('id'); });
        throw new RuntimeException('Deliberately incomplete baseline');
    }
};
MIGRATION);
        expect(fn () => $fixture->align->plan(['all-safe']))->toThrow(RuntimeException::class)
            ->and($fixture->fingerprint())->toBe($before)
            ->and($fixture->recordName())->toBe('Sample record A');
        unlink($fixture->directory.'/database/migrations/2026_01_05_000000_fail.php');
        $plan = $fixture->align->plan(['all-safe']);
        $fixture->align->apply($plan['id'], $plan['signature']);
        expect($fixture->align->diff()['differences'])->toBe([])
            ->and($fixture->recordName())->toBe('Sample record A');
    } finally {
        $fixture->close();
    }
});

<?php

declare(strict_types=1);

use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Korioinc\SchemaAlign\Database\TargetConnection;
use Korioinc\SchemaAlign\Replay\ReplayNamespace;
use Korioinc\SchemaAlign\Schema\Catalog\PostgresCatalog;
use Korioinc\SchemaAlign\Tests\Support\InstalledAppFixture;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with the disposable PostgreSQL target.');
    }
    $this->installedFixture = new InstalledAppFixture($this->app);
});

afterEach(function (): void {
    if (isset($this->installedFixture)) {
        $this->installedFixture->close();
    }
});

it('diffs the installed app on first use and replays edited create migrations without release configuration', function (): void {
    $fixture = $this->installedFixture;
    $before = $fixture->fingerprint();
    $ledger = $fixture->pdo->query('SELECT migration, batch FROM '.$fixture->quotedSchema.'.migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $sentinel = getenv('SCHEMA_ALIGN_HOST_SENTINEL');
    putenv('SCHEMA_ALIGN_HOST_SENTINEL=host-only-installed-app');

    try {
        $fixture->align->diff();
        $fixture->removeOptionalLabel();
        $fixture->align->diff();
    } finally {
        putenv($sentinel === false ? 'SCHEMA_ALIGN_HOST_SENTINEL' : 'SCHEMA_ALIGN_HOST_SENTINEL='.$sentinel);
    }
    expect($fixture->fingerprint())->toBe($before)
        ->and($fixture->recordName())->toBe('Sample record A');
    $plan = $fixture->align->plan(['all-safe']);
    expect($fixture->fingerprint())->toBe($before);
    $fixture->align->apply($plan['id'], $plan['signature']);
    $clean = $fixture->align->diff();
    expect($clean['differences'])->toBe([])
        ->and($fixture->recordName())->toBe('Sample record A')
        ->and($fixture->pdo->query('SELECT migration, batch FROM '.$fixture->quotedSchema.'.migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($ledger);
    $fixture->pdo->exec("UPDATE {$fixture->quotedSchema}.sample_records SET sample_code = 'sample-code' WHERE id = 1");
    expect($fixture->pdo->query('SELECT sample_code FROM '.$fixture->quotedSchema.'.sample_records WHERE id = 1')->fetchColumn())->toBe('sample-code');
    expect(fn () => $fixture->pdo->query('SELECT optional_label FROM '.$fixture->quotedSchema.'.sample_records'))->toThrow(PDOException::class);
    $fixture->pdo->exec('INSERT INTO '.$fixture->quotedSchema.'.provider_notes (id) VALUES (42)');
    expect($fixture->pdo->query('SELECT id FROM '.$fixture->quotedSchema.'.provider_notes')->fetchColumn())->toBe(42);
});

it('diffs and applies with a legacy migration ledger sequence without changing the ledger', function (): void {
    $fixture = $this->installedFixture;
    $sequence = $fixture->quotedSchema.'.migrations_id_seq';
    $fixture->pdo->exec('ALTER SEQUENCE '.$sequence.' AS bigint MAXVALUE 2147483647');
    $ledgerQuery = 'SELECT id, migration, batch FROM '.$fixture->quotedSchema.'.migrations ORDER BY id';
    $stateQuery = 'SELECT last_value, is_called FROM '.$sequence;
    $ledger = $fixture->pdo->query($ledgerQuery)->fetchAll(PDO::FETCH_ASSOC);
    $state = $fixture->pdo->query($stateQuery)->fetch(PDO::FETCH_ASSOC);

    $comparison = $fixture->align->diff();
    $plan = $fixture->align->pin($comparison, ['all-safe']);
    $fixture->align->apply($plan['id'], $plan['signature']);
    $fixture->pdo->exec("UPDATE {$fixture->quotedSchema}.sample_records SET optional_label = 'Aligned' WHERE id = 1");

    expect($fixture->pdo->query('SELECT optional_label FROM '.$fixture->quotedSchema.'.sample_records WHERE id = 1')->fetchColumn())->toBe('Aligned')
        ->and($fixture->pdo->query($ledgerQuery)->fetchAll(PDO::FETCH_ASSOC))->toBe($ledger)
        ->and($fixture->pdo->query($stateQuery)->fetch(PDO::FETCH_ASSOC))->toBe($state)
        ->and($fixture->recordName())->toBe('Sample record A');
});

it('replays and cleans up with an authoritative stale package classmap', function (): void {
    $fixture = $this->installedFixture;
    $before = $fixture->fingerprint();
    (new Process(['composer', 'dump-autoload', '--classmap-authoritative', '--no-scripts', '--no-plugins', '--no-interaction'], $fixture->directory, [
        'COMPOSER_DISABLE_NETWORK' => '1',
    ], timeout: 60))->mustRun();
    $autoload = $fixture->directory.'/vendor/autoload.php';
    $contents = str_replace('return ComposerAutoloaderInit', '$loader = ComposerAutoloaderInit', file_get_contents($autoload), $replaced);
    expect($replaced)->toBe(1);
    file_put_contents($autoload, $contents.<<<'PHP'

$stale = [];
foreach ($loader->getClassMap() as $class => $path) {
    if (str_starts_with($class, 'Korioinc\\SchemaAlign\\')) {
        $stale[$class] = __DIR__.'/missing-package-class.php';
    }
}
$loader->addClassMap($stale);
return $loader;
PHP);

    $comparison = $fixture->align->diff();
    expect($comparison['errors'])->toBe([])
        ->and($comparison['source']['replay']['cleanup']['status'])->toBe('cleaned')
        ->and($comparison['desired']['tables'][$fixture->schema.'.sample_records']['columns'])->toHaveKey('sample_code')
        ->and($fixture->fingerprint())->toBe($before)
        ->and($fixture->recordName())->toBe('Sample record A');
});

it('refuses qualified replay changes to ambiguous original schema identifiers and preserves data', function (bool $truncated): void {
    $f = $this->installedFixture;
    $schema = $truncated ? str_repeat('a', 53).bin2hex(random_bytes(5)) : 'sa_original_"'.bin2hex(random_bytes(5));
    $qualifier = $truncated ? $schema.'_suffix' : $schema;
    $quoted = '"'.str_replace('"', '""', $schema).'"';
    $f->pdo->exec('CREATE SCHEMA '.$quoted);

    try {
        $f->pdo->exec('CREATE TABLE '.$quoted.'.sample_records (id bigint NOT NULL, name varchar(255) NOT NULL)');
        $f->pdo->exec('INSERT INTO '.$quoted.".sample_records VALUES (1, 'Original sample item')");
        $config = $f->app->make('config');
        $config->set('schema-align.schemas', [$schema]);
        $config->set('database.connections.installed_target.search_path', $schema);
        file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_original_scope.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\Schema::table('.var_export($qualifier.'.sample_records', true).', function (\\Illuminate\\Database\\Schema\\Blueprint $table): void { $table->string("unauthorized")->nullable(); }); } };');
        $catalog = new PostgresCatalog($f->pdo, [$schema]);
        $before = $catalog->inspect()->fingerprint();

        expect(fn () => $f->align->diff())->toThrow(RuntimeException::class)
            ->and($catalog->inspect()->fingerprint())->toBe($before)
            ->and($f->pdo->query('SELECT name FROM '.$quoted.'.sample_records WHERE id=1')->fetchColumn())->toBe('Original sample item');
    } finally {
        $f->pdo->exec('DROP SCHEMA '.$quoted.' CASCADE');
    }
})->with(['escaped quote' => false, 'server-truncated identifier' => true]);

it('refuses original PostgreSQL writes across dialect-specific tokens during replay', function (bool $carriageReturn): void {
    $f = $this->installedFixture;
    $sql = $carriageReturn
        ? 'UPDATE '.$f->quotedSchema." -- ordinary comment\r.sample_records SET name='Unauthorized update'"
        : 'WITH data AS (SELECT \'{"name":"Unauthorized update"}\'::jsonb #>> \'{name}\' AS value) UPDATE '.$f->quotedSchema.'.sample_records SET name=(SELECT value FROM data)';
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_original_scope.php',
        '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');
    $before = $f->fingerprint();

    expect(fn () => $f->align->diff())->toThrow(RuntimeException::class)
        ->and($f->fingerprint())->toBe($before)
        ->and($f->recordName())->toBe('Sample record A');
})->with(['JSON operator' => false, 'CR-only comment' => true]);

it('refuses replay DDL outside the selected schemas and preserves the external table', function (bool $temporaryName): void {
    $f = $this->installedFixture;
    $schema = $temporaryName ? 'align-9876-'.substr(bin2hex(random_bytes(3)), 0, 5) : 'public';
    $table = 'sa_external_'.bin2hex(random_bytes(8));
    $qualified = '"'.$schema.'"."'.$table.'"';

    if ($temporaryName) {
        $f->pdo->exec('CREATE SCHEMA "'.$schema.'"');
    }
    $f->pdo->exec('CREATE TABLE '.$qualified.' (id integer PRIMARY KEY, name text NOT NULL)');
    $f->pdo->exec('INSERT INTO '.$qualified." VALUES (1, 'Outside selected scope')");

    try {
        file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_external_scope.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\Schema::table('.var_export($schema.'.'.$table, true).', function (\\Illuminate\\Database\\Schema\\Blueprint $table): void { $table->string("unauthorized")->nullable(); }); } };');
        $catalog = new PostgresCatalog($f->pdo, [$schema]);
        $before = $catalog->inspect()->fingerprint();

        expect(fn () => $f->align->diff())->toThrow(RuntimeException::class)
            ->and($catalog->inspect()->fingerprint())->toBe($before)
            ->and($f->pdo->query('SELECT name FROM '.$qualified.' WHERE id=1')->fetchColumn())->toBe('Outside selected scope');
    } finally {
        $f->pdo->exec('DROP TABLE '.$qualified);

        if ($temporaryName) {
            $f->pdo->exec('DROP SCHEMA "'.$schema.'" RESTRICT');
        }
    }
})->with(['unselected public schema' => false, 'another temporary namespace' => true]);

it('replays qualified DDL with column comments and numeric defaults in its owned schema', function (): void {
    $f = $this->installedFixture;
    $before = $f->fingerprint();
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_amounts.php', <<<'MIGRATION'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        $schema = array_values(config('schema-align.replay.schema_map'))[0];
        \Illuminate\Support\Facades\Schema::create($schema.'.amounts', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->bigInteger('id');
            $table->decimal('amount', 12, 2)->default(\Illuminate\Support\Facades\DB::raw('1.25'))->comment('Unit amount');
        });
        \Illuminate\Support\Facades\DB::table($schema.'.amounts')->insert(['id' => 1]);
        if (\Illuminate\Support\Facades\DB::table($schema.'.amounts')->value('amount') !== '1.25') {
            throw new \RuntimeException('The replayed numeric default did not produce the expected amount.');
        }
    }
};
MIGRATION);
    $f->align->diff();

    expect($f->fingerprint())->toBe($before)
        ->and($f->recordName())->toBe('Sample record A');
});

it('refuses dropping the original schema during replay and preserves its rows', function (): void {
    $f = $this->installedFixture;
    $sql = 'DROP SCHEMA '.$f->quotedSchema.' CASCADE';
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_drop_original.php',
        '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');
    $before = $f->fingerprint();

    expect(fn () => $f->align->diff())->toThrow(RuntimeException::class, 'global scope')
        ->and($f->fingerprint())->toBe($before)
        ->and($f->recordName())->toBe('Sample record A');
});

it('blocks quoted PostgreSQL session escapes before original rows can change', function (string $escape): void {
    $f = $this->installedFixture;
    $table = 'sa_session_'.bin2hex(random_bytes(8));
    $f->pdo->exec('CREATE TABLE public."'.$table.'" (name text NOT NULL)');
    $f->pdo->exec('INSERT INTO public."'.$table.'" VALUES (\'Original row\')');

    try {
        $write = 'UPDATE "'.$table.'" SET name=\'Unauthorized change\'';
        file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_session_escape.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($escape, true).'); \\Illuminate\\Support\\Facades\\DB::statement('.var_export($write, true).'); } };');
        $error = null;

        try {
            $f->align->diff();
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        expect($f->pdo->query('SELECT name FROM public."'.$table.'"')->fetchColumn())->toBe('Original row')
            ->and($error)->toBeInstanceOf(RuntimeException::class);
    } finally {
        $f->pdo->exec('DROP TABLE public."'.$table.'"');
    }
})->with([
    'local quoted setting' => 'SET LOCAL "search_path" TO public',
    'session quoted setting with comment' => 'SET SESSION /* scope */ "search_path" = public',
    'quoted configuration function' => 'SELECT "pg_catalog"."set_config"(\'search_path\', \'public\', true)',
    'procedural configuration function' => 'DO $$ BEGIN PERFORM set_config(\'search_path\', \'public\', false); END $$',
]);

it('preserves original PostgreSQL schema privileges during replay', function (bool $revoke): void {
    $f = $this->installedFixture;

    if ($revoke) {
        $f->pdo->exec('GRANT USAGE ON SCHEMA '.$f->quotedSchema.' TO PUBLIC');
    }
    $acl = fn (): mixed => $f->pdo->query('SELECT nspacl::text FROM pg_namespace WHERE nspname='.$f->pdo->quote($f->schema))->fetchColumn();
    $before = $acl();
    $sql = $revoke ? 'REVOKE USAGE ON SCHEMA '.$f->quotedSchema.' FROM PUBLIC' : 'GRANT USAGE ON SCHEMA '.$f->quotedSchema.' TO PUBLIC';
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_privileges.php',
        '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');
    $error = null;

    try {
        $f->align->diff();
    } catch (RuntimeException $caught) {
        $error = $caught;
    }
    expect($acl())->toBe($before)->and($error)->toBeInstanceOf(RuntimeException::class)
        ->and($f->recordName())->toBe('Sample record A');
})->with(['grant' => false, 'revoke' => true]);

it('preserves default PostgreSQL privileges during replay', function (): void {
    $f = $this->installedFixture;
    $acl = fn (): array => $f->pdo->query('SELECT defaclobjtype, defaclacl::text FROM pg_default_acl WHERE defaclnamespace=(SELECT oid FROM pg_namespace WHERE nspname='.$f->pdo->quote($f->schema).') ORDER BY defaclobjtype')->fetchAll(PDO::FETCH_ASSOC);
    $before = $acl();
    $sql = 'ALTER DEFAULT PRIVILEGES IN SCHEMA '.$f->quotedSchema.' GRANT SELECT ON TABLES TO PUBLIC';
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_default_privileges.php',
        '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');
    $error = null;

    try {
        $f->align->diff();
    } catch (RuntimeException $caught) {
        $error = $caught;
    }
    expect($acl())->toBe($before)->and($error)->toBeInstanceOf(RuntimeException::class);
});

it('replays numeric PostgreSQL schemas and cleans every allocated namespace', function (string $schema): void {
    $f = $this->installedFixture;
    $quoted = '"'.$schema.'"';
    $f->pdo->exec('CREATE SCHEMA '.$quoted);

    try {
        $f->pdo->exec('CREATE TABLE '.$quoted.'.sample_records (id bigint NOT NULL, name varchar(255) NOT NULL)');
        $f->pdo->exec('INSERT INTO '.$quoted.'.sample_records VALUES (1, \'Numeric schema row\')');
        $config = $f->app->make('config');
        $config->set('schema-align.schemas', [$schema]);
        $config->set('database.connections.installed_target.search_path', $schema);
        $plan = $f->align->plan(['all-safe']);
        $applied = $f->align->apply($plan['id'], $plan['signature']);
        expect($applied['status'])->toBe('applied');
        $f->pdo->exec('UPDATE '.$quoted.'.sample_records SET sample_code=\'Retained on retry\' WHERE id=1');
        $retried = $f->align->apply($plan['id'], $plan['signature']);
        expect($retried['status'])->toBe('applied')
            ->and($f->align->store()->status($plan['id'])['status'])->toBe('applied')
            ->and($f->pdo->query('SELECT sample_code FROM '.$quoted.'.sample_records WHERE id=1')->fetchColumn())->toBe('Retained on retry');
        $comparison = $f->align->diff();

        expect($comparison['differences'])->toBe([])
            ->and($f->pdo->query('SELECT name FROM '.$quoted.'.sample_records WHERE id=1')->fetchColumn())->toBe('Numeric schema row');
        foreach ($comparison['source']['replay']['schema_map'] as $physical) {
            expect($f->pdo->query('SELECT count(*) FROM pg_namespace WHERE nspname='.$f->pdo->quote($physical))->fetchColumn())->toBe(0);
        }
    } finally {
        $f->pdo->exec('DROP SCHEMA '.$quoted.' CASCADE');
    }
})->with(['2026', '0']);

it('preserves original PostgreSQL publications during replay', function (): void {
    $f = $this->installedFixture;
    $publication = 'sa_publication_'.bin2hex(random_bytes(8));
    $f->pdo->exec('CREATE PUBLICATION "'.$publication.'"');

    try {
        $sql = 'DROP PUBLICATION IF EXISTS "'.$publication.'"';
        file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_publication.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');
        $error = null;

        try {
            $f->align->diff();
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        expect($f->pdo->query('SELECT count(*) FROM pg_publication WHERE pubname='.$f->pdo->quote($publication))->fetchColumn())->toBe(1)
            ->and($error)->toBeInstanceOf(RuntimeException::class);
    } finally {
        $f->pdo->exec('DROP PUBLICATION IF EXISTS "'.$publication.'"');
    }
});

it('preserves original PostgreSQL schema comments during replay', function (): void {
    $f = $this->installedFixture;
    $f->pdo->exec('COMMENT ON SCHEMA '.$f->quotedSchema.' IS \'Original schema comment\'');
    $sql = 'COMMENT ON SCHEMA '.$f->quotedSchema.' IS \'Changed by replay\'';
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_schema_comment.php',
        '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');
    $error = null;

    try {
        $f->align->diff();
    } catch (RuntimeException $caught) {
        $error = $caught;
    }
    expect($f->pdo->query("SELECT obj_description(oid, 'pg_namespace') FROM pg_namespace WHERE nspname=".$f->pdo->quote($f->schema))->fetchColumn())->toBe('Original schema comment')
        ->and($error)->toBeInstanceOf(RuntimeException::class);
});

it('refuses SELECT INTO outside the owned replay schemas', function (string $form): void {
    $f = $this->installedFixture;
    $table = 'sa_select_into_'.bin2hex(random_bytes(8));
    $sql = match ($form) {
        'direct' => 'SELECT 1 AS id INTO public."'.$table.'"',
        'cte' => 'WITH source AS (SELECT 1 AS id) SELECT id INTO public."'.$table.'" FROM source',
        'parenthesized' => 'WITH source AS (SELECT 1 AS id) (SELECT id INTO public."'.$table.'" FROM source)',
        'keyword name' => 'WITH update AS (SELECT 1 AS id) SELECT id INTO public."'.$table.'" FROM update',
    };
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_select_into.php',
        '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\DB::statement('.var_export($sql, true).'); } };');

    try {
        $error = null;

        try {
            $f->align->diff();
        } catch (RuntimeException $caught) {
            $error = $caught;
        }
        expect($f->pdo->query('SELECT to_regclass('.$f->pdo->quote('public.'.$table).')')->fetchColumn())->toBeNull()
            ->and($error)->toBeInstanceOf(RuntimeException::class);
    } finally {
        $f->pdo->exec('DROP TABLE IF EXISTS public."'.$table.'"');
    }
})->with(['direct', 'cte', 'parenthesized', 'keyword name']);

it('replays a column drop without COLUMN when its name is also a global object type', function (): void {
    $f = $this->installedFixture;
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_drop_role_column.php', <<<'MIGRATION'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\DB::statement('CREATE TABLE role_notes (id bigint NOT NULL, role text)');
        \Illuminate\Support\Facades\DB::statement('ALTER TABLE role_notes DROP role');
    }
};
MIGRATION);
    $before = $f->fingerprint();
    $comparison = $f->align->diff();
    expect($comparison['desired']['tables'][$f->schema.'.role_notes']['columns'])->toHaveKey('id')->not->toHaveKey('role')
        ->and($f->fingerprint())->toBe($before)
        ->and($f->recordName())->toBe('Sample record A');
});

it('preserves effective cached app configuration when replaying migrations', function (): void {
    $f = $this->installedFixture;
    file_put_contents($f->directory.'/config/schema-options.php', <<<'CONFIG'
<?php
return ['column' => json_decode(env('SCHEMA_ALIGN_CACHED_OPTIONS', ''), true, 512, JSON_THROW_ON_ERROR)['column']];
CONFIG);
    $config = $f->app->make('config')->all();
    $config['schema-options'] = ['column' => 'runtime_note', 'ratio' => 1.0,
        'storage' => $f->app->storagePath('private'), 'source' => $f->app->basePath('app')];
    file_put_contents($f->app->getCachedConfigPath(), '<?php return '.var_export($config, true).';');
    (new LoadConfiguration)->bootstrap($f->app);
    $f->pdo->exec('ALTER TABLE '.$f->quotedSchema.'.sample_records ADD COLUMN runtime_note varchar(255)');
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_configured_column.php', <<<'MIGRATION'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        if (config('schema-options.ratio') !== 1.0
            || config('schema-options.storage') !== storage_path('private')
            || config('schema-options.source') !== base_path('app')) {
            throw new RuntimeException('Effective configuration types or relocated paths differ.');
        }
        \Illuminate\Support\Facades\Schema::table('sample_records', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string(config('schema-options.column'))->nullable();
        });
    }
};
MIGRATION);
    $before = $f->fingerprint();
    $comparison = $f->align->diff();
    $columns = $comparison['desired']['tables'][$f->schema.'.sample_records']['columns'];

    expect($f->app->configurationIsCached())->toBeTrue()
        ->and($columns)->toHaveKey('runtime_note')->not->toHaveKey('fallback_note')
        ->and($f->fingerprint())->toBe($before)
        ->and($f->recordName())->toBe('Sample record A');
});

it('redacts effective application secrets from replay failures', function (): void {
    $f = $this->installedFixture;
    $secret = 'runtime-secret-'.bin2hex(random_bytes(16));
    $f->app->make('config')->set('schema-options.api_key', $secret);
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_fail.php', <<<'MIGRATION'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        throw new RuntimeException('configured secret: '.config('schema-options.api_key'));
    }
};
MIGRATION);
    $failure = null;

    try {
        $f->align->diff();
    } catch (RuntimeException $error) {
        $failure = $error;
    }

    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($failure?->getMessage())->toContain('configured secret: [REDACTED]')->not->toContain($secret)
        ->and($f->recordName())->toBe('Sample record A');
});

it('applies all-safe without a foreign key whose unique index needs manual repair', function (): void {
    $f = $this->installedFixture;
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_unique_parent.php', <<<'MIGRATION'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\DB::statement('CREATE UNIQUE INDEX sample_records_id_unique ON sample_records (id)');
        \Illuminate\Support\Facades\Schema::create('sample_links', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->bigInteger('sample_record_id');
            $table->foreign('sample_record_id')->references('id')->on('sample_records');
        });
    }
};
MIGRATION);
    $comparison = $f->align->diff();
    $foreign = array_values(array_filter($comparison['differences'], static fn (array $operation): bool => $operation['name'] === 'sample_links_sample_record_id_foreign'))[0];
    expect($foreign['automatic'])->toBeFalse();
    $plan = $f->align->pin($comparison, ['all-safe']);
    expect(array_column($plan['operations'], 'id'))->not->toContain($foreign['id']);
    $f->align->apply($plan['id'], $plan['signature']);
    $f->pdo->exec('INSERT INTO '.$f->quotedSchema.'.sample_links (sample_record_id) VALUES (1)');
    expect($f->pdo->query('SELECT sample_record_id FROM '.$f->quotedSchema.'.sample_links')->fetchColumn())->toBe(1)
        ->and($f->recordName())->toBe('Sample record A');
});

it('applies an approved installed-app upgrade as superuser while preserving original data and migration ledger', function (): void {
    if (getenv('SCHEMA_ALIGN_SUPERUSER_TESTS') !== '1') {
        $this->markTestSkipped('Explicit isolated-superuser test opt-in is required.');
    }
    $fixture = $this->installedFixture;
    $config = $fixture->app->make('config');
    $config->set('database.connections.installed_target.username', getenv('SCHEMA_ALIGN_TEST_ADMIN_USER'));
    $config->set('database.connections.installed_target.password', getenv('SCHEMA_ALIGN_TEST_ADMIN_PASSWORD'));
    $before = $fixture->fingerprint();
    $ledger = $fixture->pdo->query('SELECT migration, batch FROM '.$fixture->quotedSchema.'.migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $comparison = $fixture->align->diff();
    expect($fixture->fingerprint())->toBe($before)
        ->and($fixture->recordName())->toBe('Sample record A');
    $plan = $fixture->align->pin($comparison, ['all-safe']);
    $fixture->align->apply($plan['id'], $plan['signature']);
    $fixture->pdo->exec("UPDATE {$fixture->quotedSchema}.sample_records SET sample_code = 'approved-admin-upgrade' WHERE id = 1");

    expect($fixture->pdo->query('SELECT sample_code FROM '.$fixture->quotedSchema.'.sample_records WHERE id = 1')->fetchColumn())->toBe('approved-admin-upgrade')
        ->and($fixture->recordName())->toBe('Sample record A')
        ->and($fixture->pdo->query('SELECT migration, batch FROM '.$fixture->quotedSchema.'.migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($ledger);
});

it('reconciles live completion into the journal without repeating original DDL', function (): void {
    $f = $this->installedFixture;
    $plan = $f->align->plan(['all-safe']);
    $f->align->apply($plan['id'], $plan['signature']);
    $f->pdo->exec("UPDATE {$f->quotedSchema}.sample_records SET sample_code='Committed before journal loss' WHERE id=1");
    $f->align->store()->record($plan['id'], ['status' => 'executing']);
    $f->align->finishApply($plan['id'], $plan['signature']);

    expect($f->align->store()->status($plan['id'])['status'])->toBe('applied')
        ->and($f->pdo->query('SELECT sample_code FROM '.$f->quotedSchema.'.sample_records WHERE id=1')->fetchColumn())->toBe('Committed before journal loss')
        ->and($f->recordName())->toBe('Sample record A');
});

it('removes abandoned replay schemas after successful apply without deleting another binding or original data', function (): void {
    $f = $this->installedFixture;
    $comparison = $f->align->diff();
    $plan = $f->align->pin($comparison, ['all-safe']);
    $ledger = $f->pdo->query('SELECT migration, batch FROM '.$f->quotedSchema.'.migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $manager = new ReplayNamespace($f->pdo, $comparison['binding'], $comparison['actual']['identity'], [$f->schema]);
    $other = new ReplayNamespace($f->pdo, array_replace($comparison['binding'], ['instance' => 'another-installed-app']), $comparison['actual']['identity'], [$f->schema]);
    $owned = $manager->create();
    $unrelated = $other->create();
    $ownedTable = '"'.$owned['namespaces'][0].'".sentinel';
    $otherTable = '"'.$unrelated['namespaces'][0].'".sentinel';

    try {
        foreach ([$ownedTable, $otherTable] as $table) {
            $f->pdo->exec('CREATE TABLE '.$table.' (value integer)');
            $f->pdo->exec('INSERT INTO '.$table.' VALUES (37)');
        }
        $manager->release();
        $other->release();
        $f->align->apply($plan['id'], $plan['signature']);

        expect(fn () => $f->pdo->query('SELECT value FROM '.$ownedTable))->toThrow(PDOException::class);
        expect((int) $f->pdo->query('SELECT value FROM '.$otherTable)->fetchColumn())->toBe(37)
            ->and($f->recordName())->toBe('Sample record A')
            ->and($f->pdo->query('SELECT migration, batch FROM '.$f->quotedSchema.'.migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC))->toBe($ledger);
        $f->pdo->exec("UPDATE {$f->quotedSchema}.sample_records SET sample_code = 'applied-and-retained' WHERE id = 1");
        expect($f->pdo->query('SELECT sample_code FROM '.$f->quotedSchema.'.sample_records WHERE id = 1')->fetchColumn())->toBe('applied-and-retained');
    } finally {
        $manager->cleanup($owned['run_id']);
        $other->cleanup($unrelated['run_id']);
        $manager->release();
        $other->release();
    }
});

it('retains a live replay session after committed apply and finalizes later without repeating original DDL', function (): void {
    $f = $this->installedFixture;
    $comparison = $f->align->diff();
    $plan = $f->align->pin($comparison, ['all-safe']);
    $manager = new ReplayNamespace($f->pdo, $comparison['binding'], $comparison['actual']['identity'], [$f->schema]);
    $resource = $manager->create();
    $connection = $f->app->make('config')->get('database.connections.installed_target');
    $worker = new ReplayNamespace(TargetConnection::writable($connection, $comparison['binding']['instance']), $comparison['binding'], $comparison['actual']['identity'], [$f->schema]);
    $session = TargetConnection::writable($connection, $comparison['binding']['instance']);
    $table = '"'.$resource['namespaces'][0].'".sentinel';

    try {
        $worker->workerLease($resource);
        $worker->registerSession($resource, $session);
        $session->exec('CREATE TABLE '.$table.' (value integer)');
        $session->exec('INSERT INTO '.$table.' VALUES (43)');
        $worker->releaseWorker();
        $manager->release();
        $f->align->apply($plan['id'], $plan['signature']);
        expect((int) $session->query('SELECT value FROM '.$table)->fetchColumn())->toBe(43);
        $f->pdo->exec("UPDATE {$f->quotedSchema}.sample_records SET sample_code = 'committed-before-cleanup' WHERE id = 1");
        $session = null;
        $f->align->finishApply($plan['id'], $plan['signature']);
        $f->align->finishApply($plan['id'], $plan['signature']);

        expect(fn () => $f->pdo->query('SELECT value FROM '.$table))->toThrow(PDOException::class);
        expect($f->pdo->query('SELECT sample_code FROM '.$f->quotedSchema.'.sample_records WHERE id = 1')->fetchColumn())->toBe('committed-before-cleanup')
            ->and($f->recordName())->toBe('Sample record A');
    } finally {
        $session = null;
        $worker->releaseWorker();
        $manager->cleanup($resource['run_id']);
        $manager->release();
    }
});

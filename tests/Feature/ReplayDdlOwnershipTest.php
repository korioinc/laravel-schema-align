<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Tests\Support\InstalledAppFixture;

it('preserves system table comments when superuser replay uses an unqualified DDL target', function (bool $shadow): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1' || getenv('SCHEMA_ALIGN_SUPERUSER_TESTS') !== '1') {
        $this->markTestSkipped('Explicit disposable PostgreSQL administrator tests are required.');
    }
    $fixture = new InstalledAppFixture($this->app);
    $admin = new PDO((string) getenv('SCHEMA_ALIGN_TEST_DSN'), (string) getenv('SCHEMA_ALIGN_TEST_ADMIN_USER'), getenv('SCHEMA_ALIGN_TEST_ADMIN_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $commentQuery = "SELECT obj_description('pg_catalog.pg_class'::regclass, 'pg_class')";
    $originalComment = $admin->query($commentQuery)->fetchColumn();

    try {
        $config = $fixture->app->make('config');
        $config->set('database.connections.installed_target.username', getenv('SCHEMA_ALIGN_TEST_ADMIN_USER'));
        $config->set('database.connections.installed_target.password', getenv('SCHEMA_ALIGN_TEST_ADMIN_PASSWORD'));
        $migration = <<<'MIGRATION'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        if (SHADOW_TABLE) {
            $schema = \Illuminate\Support\Facades\DB::selectOne('SELECT current_schema() AS name')->name;
            \Illuminate\Support\Facades\DB::statement('CREATE TABLE "'.str_replace('"', '""', $schema).'".pg_class (id integer)');
        }
        $resolved = \Illuminate\Support\Facades\DB::selectOne("SELECT 'pg_class'::regclass::oid AS actual, 'pg_catalog.pg_class'::regclass::oid AS catalog");
        if ($resolved->actual !== $resolved->catalog) {
            throw new RuntimeException('The test did not resolve the system catalog first.');
        }
        \Illuminate\Support\Facades\DB::statement("COMMENT ON TABLE pg_class IS 'Changed by replay DDL'");
    }
};
MIGRATION;
        file_put_contents($fixture->directory.'/database/migrations/2026_01_08_000000_catalog_ddl.php', str_replace('SHADOW_TABLE', $shadow ? 'true' : 'false', $migration));
        $before = $fixture->fingerprint();
        $failure = null;

        try {
            $fixture->align->diff();
        } catch (RuntimeException $error) {
            $failure = $error;
        }

        expect($admin->query($commentQuery)->fetchColumn())->toBe($originalComment)
            ->and($fixture->fingerprint())->toBe($before)
            ->and($failure?->getMessage())->toContain('replay DDL has an unqualified PostgreSQL system relation target');
    } finally {
        $admin->exec('COMMENT ON TABLE pg_catalog.pg_class IS '.($originalComment === null ? 'NULL' : $admin->quote((string) $originalComment)));
        $fixture->close();
    }
})->with(['without shadow' => false, 'with owned shadow' => true]);

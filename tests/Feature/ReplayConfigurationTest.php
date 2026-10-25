<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Tests\Support\InstalledAppFixture;

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

it('applies migrations driven by merged package lists without duplicating columns', function (): void {
    $f = $this->installedFixture;
    $namespace = 'InstalledFixture'.substr($f->schema, 13);
    file_put_contents($f->directory.'/config/note-columns.php', "<?php return ['custom_note'];\n");
    file_put_contents($f->directory.'/app/Providers/NoteProvider.php', '<?php namespace '.$namespace.';'.<<<'PHP'

class NoteProvider extends \Illuminate\Support\ServiceProvider {
    public function register(): void {
        $this->mergeConfigFrom($this->app->configPath('note-columns.php'), 'note-columns');
    }
}
PHP);
    $providers = $f->directory.'/bootstrap/providers.php';
    file_put_contents($providers, str_replace('return [', "require_once dirname(__DIR__).'/app/Providers/NoteProvider.php';\nreturn [\\".$namespace.'\\NoteProvider::class, ', file_get_contents($providers)));
    require $f->directory.'/app/Providers/NoteProvider.php';
    $f->app->register($namespace.'\\NoteProvider');
    file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_configured_notes.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::table('sample_records', function (\Illuminate\Database\Schema\Blueprint $table): void {
            foreach (config('note-columns') as $column) {
                $table->string($column)->nullable();
            }
        });
    }
};
PHP);
    $before = $f->fingerprint();
    $plan = $f->align->plan(['all-supported']);
    expect($f->fingerprint())->toBe($before);
    $f->align->apply($plan['id'], $plan['signature']);
    $f->pdo->exec("UPDATE {$f->quotedSchema}.sample_records SET custom_note = 'Package note' WHERE id = 1");

    expect($f->pdo->query('SELECT custom_note FROM '.$f->quotedSchema.'.sample_records WHERE id = 1')->fetchColumn())->toBe('Package note')
        ->and($f->recordName())->toBe('Sample record A');
});

it('applies configured migrations from normalized aliases of the application base path', function (): void {
    $f = $this->installedFixture;
    $base = realpath($f->directory);
    $f->app->setBasePath($base.'/bootstrap/..');
    file_put_contents($f->directory.'/bootstrap/app.php', "<?php return \\Illuminate\\Foundation\\Application::configure(basePath: __DIR__.'/..')->create();\n");
    file_put_contents($f->directory.'/config/migration-source.php', "<?php return ['path' => dirname(__DIR__).'/app/provider-migrations', 'storage' => realpath(dirname(__DIR__).'/storage').'/notes'];\n");
    $f->app->make('config')->set('migration-source', require $f->directory.'/config/migration-source.php');
    $provider = $f->directory.'/app/Providers/FixtureProvider.php';
    file_put_contents($provider, str_replace("\$this->app->basePath('app/provider-migrations')", "config('migration-source.path')", file_get_contents($provider)));
    file_put_contents($f->directory.'/app/provider-migrations/2026_01_08_000000_configured_notes.php', <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        if (config('migration-source.storage') !== str_replace('\\', '/', storage_path('notes'))) {
            throw new RuntimeException('Configured storage escaped private worker storage.');
        }
        \Illuminate\Support\Facades\Schema::table('sample_records', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string('custom_note')->nullable();
        });
    }
};
PHP);
    $before = $f->fingerprint();
    $plan = $f->align->plan(['all-supported']);
    expect($f->fingerprint())->toBe($before);
    $f->align->apply($plan['id'], $plan['signature']);
    $f->pdo->exec("UPDATE {$f->quotedSchema}.sample_records SET custom_note = 'Relocated note' WHERE id = 1");

    expect($f->pdo->query('SELECT custom_note FROM '.$f->quotedSchema.'.sample_records WHERE id = 1')->fetchColumn())->toBe('Relocated note')
        ->and($f->recordName())->toBe('Sample record A');
});

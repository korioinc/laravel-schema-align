<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Korioinc\SchemaAlign\Tests\Support\InstalledAppFixture;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

it('keeps JSON reviewed SQL identical to the signed plan and applied identifiers', function (bool $decorated): void {
    if (getenv('SCHEMA_ALIGN_AUTO_REPLAY_TESTS') !== '1') {
        $this->markTestSkipped('Set SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1 with the disposable PostgreSQL target.');
    }
    $f = new InstalledAppFixture($this->app);

    try {
        file_put_contents($f->directory.'/database/migrations/2026_01_08_000000_tagged_column.php', <<<'MIGRATION'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::table('sample_records', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->text('<info>note</info>')->nullable();
        });
    }
};
MIGRATION);
        $kernel = $f->app->make(Kernel::class);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $decorated);
        $kernel->call('schema-align:plan', ['--json' => true], $output);
        $printed = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
        $stored = $f->align->store()->load($printed['id']);

        expect(array_column($printed['operations'], 'sql'))->toBe(array_column($stored['operations'], 'sql'));

        $kernel->call('schema-align:diff', ['--json' => true, '--details' => true], $output);
        $diff = json_decode($output->fetch(), true, 512, JSON_THROW_ON_ERROR);
        $reviewed = array_column($diff['differences'], null, 'id');
        foreach ($stored['operations'] as $operation) {
            expect($reviewed[$operation['id']]['sql'])->toBe($operation['sql']);
        }

        $f->align->apply($printed['id'], $printed['signature']);
        $f->pdo->exec('UPDATE '.$f->quotedSchema.'.sample_records SET "<info>note</info>"=\'Reviewed identifier\' WHERE id=1');
        expect($f->pdo->query('SELECT "<info>note</info>" FROM '.$f->quotedSchema.'.sample_records WHERE id=1')->fetchColumn())->toBe('Reviewed identifier')
            ->and($f->recordName())->toBe('Sample record A');
    } finally {
        $f->close();
    }
})->with(['plain output' => false, 'decorated output' => true]);

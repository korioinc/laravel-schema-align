<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Execution\ApplyExecutor;
use Korioinc\SchemaAlign\SchemaAlign;
use Korioinc\SchemaAlign\Tests\Support\ApplyFixture;

beforeEach(function (): void {
    if (! getenv('SCHEMA_ALIGN_TEST_DSN')) {
        $this->markTestSkipped('Set SCHEMA_ALIGN_TEST_DSN to the isolated disposable PostgreSQL target to run apply safety tests.');
    }
    $this->applyFixture = new ApplyFixture($this->app);
});

afterEach(function (): void {
    if (isset($this->applyFixture)) {
        $this->applyFixture->close();
    }
});

it('upgrades existing data and reconciles a repeated approved apply without rerunning DDL', function (): void {
    $fixture = $this->applyFixture;
    $plan = $fixture->plan();
    $executor = new ApplyExecutor($fixture->pdo, $fixture->align->store(), [$fixture->schema]);
    $baseline = $fixture->fingerprint();
    $journalPath = $fixture->directory.'/'.$plan['id'].'.status.json';
    $journal = file_get_contents($journalPath);

    $executor->review($plan['id'], $plan['signature'], $plan['binding']);
    expect($fixture->fingerprint())->toBe($baseline)
        ->and($fixture->itemName())->toBe('Sample item A')
        ->and(file_get_contents($journalPath))->toBe($journal);

    $fixture->align->apply($plan['id'], $plan['signature']);
    $fixture->pdo->exec("UPDATE {$fixture->quotedSchema}.sample_items SET note = 'Written after upgrade' WHERE id = 1");
    $applied = $fixture->fingerprint();
    $journal = file_get_contents($journalPath);
    $executor->review($plan['id'], $plan['signature'], $plan['binding']);
    expect($fixture->fingerprint())->toBe($applied)
        ->and(file_get_contents($journalPath))->toBe($journal);

    // Reopen the service so replay protection must use persisted state and catalog evidence.
    (new SchemaAlign($this->app))->apply($plan['id'], $plan['signature']);

    expect($fixture->itemName())->toBe('Sample item A')
        ->and($fixture->pdo->query('SELECT note FROM '.$fixture->quotedSchema.'.sample_items WHERE id = 1')->fetchColumn())
        ->toBe('Written after upgrade');

    // A completed journal cannot authorize a no-op when the live database later diverges.
    $fixture->pdo->exec('ALTER TABLE '.$fixture->quotedSchema.'.sample_items ADD COLUMN external_change integer');
    $divergent = $fixture->fingerprint();
    $journal = file_get_contents($journalPath);
    expect(fn () => $executor->review($plan['id'], $plan['signature'], $plan['binding']))->toThrow(RuntimeException::class)
        ->and(file_get_contents($journalPath))->toBe($journal);
    expect(fn () => $fixture->align->apply($plan['id'], $plan['signature']))->toThrow(RuntimeException::class)
        ->and($fixture->fingerprint())->toBe($divergent)
        ->and($fixture->itemName())->toBe('Sample item A')
        ->and($fixture->pdo->query('SELECT note FROM '.$fixture->quotedSchema.'.sample_items WHERE id = 1')->fetchColumn())->toBe('Written after upgrade');
});

it('refuses a stale plan before changing a divergent target', function (): void {
    $fixture = $this->applyFixture;
    $plan = $fixture->plan();
    $executor = new ApplyExecutor($fixture->pdo, $fixture->align->store(), [$fixture->schema]);
    $executor->review($plan['id'], $plan['signature'], $plan['binding']);
    // External DDL after review must invalidate both the next review and final apply.
    $fixture->pdo->exec('ALTER TABLE '.$fixture->quotedSchema.'.sample_items ADD COLUMN external_change integer');
    $divergent = $fixture->fingerprint();

    expect(fn () => $executor->apply($plan['id'], $plan['signature'], $plan['binding']))->toThrow(RuntimeException::class)
        ->and($fixture->fingerprint())->toBe($divergent)
        ->and($fixture->itemName())->toBe('Sample item A');

    $journalPath = $fixture->directory.'/'.$plan['id'].'.status.json';
    $journal = file_get_contents($journalPath);

    expect(fn () => $executor->review($plan['id'], $plan['signature'], $plan['binding']))->toThrow(RuntimeException::class)
        ->and($fixture->fingerprint())->toBe($divergent)
        ->and(file_get_contents($journalPath))->toBe($journal)
        ->and($fixture->itemName())->toBe('Sample item A');
});

it('rejects edited plan SQL without executing it', function (): void {
    $fixture = $this->applyFixture;
    $plan = $fixture->plan();
    $baseline = $fixture->fingerprint();
    $path = $fixture->directory.'/'.$plan['id'].'.plan.json';
    $edited = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $edited['operations'][0]['sql'] = ['DELETE FROM '.$fixture->quotedSchema.'.sample_items'];
    file_put_contents($path, json_encode($edited, JSON_THROW_ON_ERROR));

    expect(fn () => $fixture->align->apply($plan['id'], $plan['signature']))->toThrow(RuntimeException::class)
        ->and($fixture->fingerprint())->toBe($baseline)
        ->and($fixture->itemName())->toBe('Sample item A');
});

it('requires the reviewed signature before applying a valid plan', function (): void {
    $fixture = $this->applyFixture;
    $plan = $fixture->plan();
    $baseline = $fixture->fingerprint();

    expect(fn () => $fixture->align->apply($plan['id'], str_repeat('0', 64)))->toThrow(RuntimeException::class)
        ->and($fixture->fingerprint())->toBe($baseline);
});

it('refuses approval issued for another target instance', function (): void {
    $fixture = $this->applyFixture;
    $plan = $fixture->plan();
    $baseline = $fixture->fingerprint();
    config()->set('schema-align.target_instance', 'another-isolated-instance');

    expect(fn () => $fixture->align->apply($plan['id'], $plan['signature']))->toThrow(RuntimeException::class)
        ->and($fixture->fingerprint())->toBe($baseline)
        ->and($fixture->itemName())->toBe('Sample item A');
});

it('requires confirmation before the latest plan can change original objects', function (): void {
    $fixture = $this->applyFixture;
    $fixture->plan();
    $before = $fixture->fingerprint();
    $this->artisan('schema-align:apply', ['--no-interaction' => true])->run();
    expect($fixture->fingerprint())->toBe($before);
    $this->artisan('schema-align:apply')->expectsQuestion('Enter yes to apply this plan', 'no')->run();
    expect($fixture->fingerprint())->toBe($before)
        ->and($fixture->itemName())->toBe('Sample item A');
    $this->artisan('schema-align:apply')->expectsQuestion('Enter yes to apply this plan', 'yes')->run();
    $fixture->pdo->exec("UPDATE {$fixture->quotedSchema}.sample_items SET note = 'Confirmed plan' WHERE id = 1");
    expect($fixture->pdo->query('SELECT note FROM '.$fixture->quotedSchema.'.sample_items WHERE id = 1')->fetchColumn())->toBe('Confirmed plan');
});

it('rolls back earlier DDL on SQL failure and retries only after explicit baseline recovery', function (): void {
    $fixture = $this->applyFixture;
    $fixture->pdo->exec('CREATE TABLE '.$fixture->quotedSchema.'.retry_gate (enabled boolean NOT NULL)');
    $fixture->pdo->exec('INSERT INTO '.$fixture->quotedSchema.'.retry_gate VALUES (false)');
    $plan = $fixture->plan();
    $baseline = $fixture->fingerprint();
    // Issue a signed fault-injection plan: its second SQL statement fails after ADD COLUMN.
    $plan['operations'][0]['sql'][] = 'SELECT 1 / enabled::integer FROM '.$fixture->quotedSchema.'.retry_gate';
    unset($plan['signature']);
    $plan = $fixture->align->store()->save($plan);

    expect(fn () => $fixture->align->apply($plan['id'], $plan['signature']))->toThrow(PDOException::class)
        ->and($fixture->fingerprint())->toBe($baseline)
        ->and($fixture->itemName())->toBe('Sample item A');

    // Repair the external failure condition without altering the inspected schema baseline.
    $fixture->pdo->exec('UPDATE '.$fixture->quotedSchema.'.retry_gate SET enabled = true');
    expect(fn () => $fixture->align->apply($plan['id'], $plan['signature']))->toThrow(RuntimeException::class)
        ->and($fixture->fingerprint())->toBe($baseline);

    $this->artisan('schema-align:apply', [
        'plan' => $plan['id'], '--approve' => $plan['signature'], '--recover' => true,
    ])->run();
    $fixture->pdo->exec("UPDATE {$fixture->quotedSchema}.sample_items SET note = 'Recovered' WHERE id = 1");
    expect($fixture->itemName())->toBe('Sample item A')
        ->and($fixture->pdo->query('SELECT note FROM '.$fixture->quotedSchema.'.sample_items WHERE id = 1')->fetchColumn())
        ->toBe('Recovered');
});

it('refuses an overlapping package apply held by another database session without mutating data', function (): void {
    $fixture = $this->applyFixture;
    $plan = $fixture->plan();
    $before = $fixture->fingerprint();
    $fixture->pdo->query('SELECT pg_advisory_lock(1935894631, 1935894632)');

    try {
        expect(fn () => $fixture->align->apply($plan['id'], $plan['signature']))->toThrow(RuntimeException::class, 'database lock')
            ->and($fixture->fingerprint())->toBe($before)
            ->and($fixture->itemName())->toBe('Sample item A');
    } finally {
        $fixture->pdo->query('SELECT pg_advisory_unlock(1935894631, 1935894632)');
    }
    $fixture->align->apply($plan['id'], $plan['signature']);
    $fixture->pdo->exec("UPDATE {$fixture->quotedSchema}.sample_items SET note = 'Available after competing session released lock' WHERE id = 1");
    expect($fixture->itemName())->toBe('Sample item A');
});

it('requires explicit recovery for an unknown journal state even when the baseline matches', function (): void {
    $fixture = $this->applyFixture;
    $plan = $fixture->plan();
    $baseline = $fixture->fingerprint();
    $fixture->align->store()->record($plan['id'], ['status' => 'unknown']);
    $executor = new ApplyExecutor($fixture->pdo, $fixture->align->store(), [$fixture->schema]);
    $journalPath = $fixture->directory.'/'.$plan['id'].'.status.json';
    $journal = file_get_contents($journalPath);

    expect(fn () => $executor->review($plan['id'], $plan['signature'], $plan['binding']))->toThrow(RuntimeException::class)
        ->and($fixture->fingerprint())->toBe($baseline)
        ->and(file_get_contents($journalPath))->toBe($journal);
    $executor->review($plan['id'], $plan['signature'], $plan['binding'], true);
    expect($fixture->fingerprint())->toBe($baseline)
        ->and($fixture->itemName())->toBe('Sample item A')
        ->and(file_get_contents($journalPath))->toBe($journal);

    expect(fn () => $fixture->align->apply($plan['id'], $plan['signature']))->toThrow(RuntimeException::class)
        ->and($fixture->fingerprint())->toBe($baseline);

    $fixture->align->apply($plan['id'], $plan['signature'], true);
    $fixture->pdo->exec("UPDATE {$fixture->quotedSchema}.sample_items SET note = 'Verified baseline recovery' WHERE id = 1");
    expect($fixture->itemName())->toBe('Sample item A');
});

it('leaves the approved baseline and journal intact when the ledger is locked', function (string $action): void {
    $f = $this->applyFixture;
    $f->pdo->exec('CREATE TABLE '.$f->quotedSchema.'.migrations (id integer PRIMARY KEY, migration text NOT NULL, batch integer NOT NULL)');
    $f->pdo->exec("INSERT INTO {$f->quotedSchema}.migrations VALUES (1, 'baseline', 1)");
    $plan = $f->plan();
    $before = $f->fingerprint();
    $journalPath = $f->directory.'/'.$plan['id'].'.status.json';
    $journal = file_get_contents($journalPath);
    $holder = new PDO(getenv('SCHEMA_ALIGN_TEST_DSN'), getenv('SCHEMA_ALIGN_TEST_USER'), getenv('SCHEMA_ALIGN_TEST_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $executor = new ApplyExecutor($f->pdo, $f->align->store(), [$f->schema]);
    // A separate statement deadline makes an unbounded catalog read fail distinctly.
    $f->pdo->exec("SET statement_timeout = '400ms'");
    $holder->beginTransaction();
    $holder->exec('LOCK TABLE '.$f->quotedSchema.'.migrations IN ACCESS EXCLUSIVE MODE');

    try {
        $failure = null;

        try {
            $executor->{$action}($plan['id'], $plan['signature'], $plan['binding'], false, 20);
        } catch (PDOException $error) {
            $failure = $error;
        }
        $lockNotAvailable = '55P03';
        expect($failure)->toBeInstanceOf(PDOException::class)
            ->and($failure?->errorInfo[0])->toBe($lockNotAvailable);
    } finally {
        $holder->rollBack();
        $f->pdo->exec('SET statement_timeout = 0');
    }
    expect($f->fingerprint())->toBe($before)
        ->and($f->itemName())->toBe('Sample item A')
        ->and(file_get_contents($journalPath))->toBe($journal);
    // Reusing the executor proves that the failed inspection released the package lock.
    $executor->review($plan['id'], $plan['signature'], $plan['binding']);
    $executor->apply($plan['id'], $plan['signature'], $plan['binding']);
    $f->pdo->exec('SET default_transaction_read_only = off');
    $f->pdo->exec("UPDATE {$f->quotedSchema}.sample_items SET note = 'retry after ledger unlock' WHERE id = 1");
    expect($f->itemName())->toBe('Sample item A');
})->with(['review', 'apply']);

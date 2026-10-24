<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Korioinc\SchemaAlign\Planning\PlanStore;
use Korioinc\SchemaAlign\Schema\Snapshot;

beforeEach(function (): void {
    $this->planDirectory = sys_get_temp_dir().'/schema-align-latest-'.bin2hex(random_bytes(12));
    $this->planStore = new PlanStore($this->planDirectory);
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->planDirectory);
});

it('returns no latest plan for a store without a published pointer, including legacy plans', function (): void {
    expect($this->planStore->latest())->toBeNull();
    $plan = $this->planStore->save(['sql' => 'CREATE TABLE legacy (id INT)']);
    unlink($this->planDirectory.'/.latest.json');

    expect($this->planStore->latest())->toBeNull()
        ->and($this->planStore->load($plan['id'])['sql'])->toBe('CREATE TABLE legacy (id INT)');
});

it('loads the last published plan across store instances regardless of older plan modification times', function (): void {
    $old = $this->planStore->save(['target' => 'old-database', 'sql' => 'DROP TABLE old_table']);
    $this->planStore->save(['target' => 'current-database', 'sql' => 'CREATE TABLE current_table (id INT)']);
    touch($this->planDirectory.'/'.$old['id'].'.plan.json', time() + 3600);

    $latest = (new PlanStore($this->planDirectory))->latest();

    expect($latest['target'])->toBe('current-database')
        ->and($latest['sql'])->toBe('CREATE TABLE current_table (id INT)');
});

it('refuses an unsigned switch to another valid stored plan', function (): void {
    $old = $this->planStore->save(['sql' => 'DROP TABLE old_table']);
    $this->planStore->save(['sql' => 'CREATE TABLE current_table (id INT)']);
    $path = $this->planDirectory.'/.latest.json';
    $pointer = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $pointer['id'] = $old['id'];
    file_put_contents($path, json_encode($pointer, JSON_THROW_ON_ERROR));

    expect(fn () => $this->planStore->latest())->toThrow(RuntimeException::class);
});

it('does not accept a signed plan document in place of a latest pointer', function (): void {
    $old = $this->planStore->save(['purpose' => 'latest-plan', 'sql' => 'DROP TABLE old_table']);
    $this->planStore->save(['sql' => 'CREATE TABLE current_table (id INT)']);
    copy($this->planDirectory.'/'.$old['id'].'.plan.json', $this->planDirectory.'/.latest.json');

    expect(fn () => $this->planStore->latest())->toThrow(RuntimeException::class);
});

it('refuses corrupted pointer documents instead of choosing another plan', function (string $contents): void {
    $this->planStore->save(['sql' => 'DROP TABLE old_table']);
    $this->planStore->save(['sql' => 'CREATE TABLE current_table (id INT)']);
    file_put_contents($this->planDirectory.'/.latest.json', $contents);

    expect(fn () => $this->planStore->latest())->toThrow(Exception::class);
})->with(['truncated' => '{', 'unsigned' => '{}', 'invalid signature' => '{"signature":"forged"}']);

it('verifies the selected plan and never falls back when it is unavailable or altered', function (string $damage): void {
    $this->planStore->save(['sql' => 'DROP TABLE old_table']);
    $latest = $this->planStore->save(['sql' => 'CREATE TABLE current_table (id INT)']);
    $path = $this->planDirectory.'/'.$latest['id'].'.plan.json';

    if ($damage === 'missing') {
        unlink($path);
    } else {
        $latest['sql'] = 'DROP TABLE current_table';
        file_put_contents($path, json_encode($latest, JSON_THROW_ON_ERROR));
    }

    expect(fn () => $this->planStore->latest())->toThrow(RuntimeException::class);
})->with(['missing', 'altered']);

it('refuses invalid signing keys for a published pointer', function (string $damage): void {
    $this->planStore->save(['sql' => 'CREATE TABLE current_table (id INT)']);
    $path = $this->planDirectory.'/.key';

    if ($damage === 'missing') {
        unlink($path);
    } else {
        file_put_contents($path, $damage === 'malformed' ? str_repeat('z', 64) : str_repeat('0', 64));
    }

    expect(fn () => $this->planStore->latest())->toThrow(RuntimeException::class);
})->with(['missing', 'malformed', 'replaced']);

it('refuses authenticated pointers with an unsupported purpose or version', function (string $field, mixed $value): void {
    $this->planStore->save(['sql' => 'CREATE TABLE current_table (id INT)']);
    $path = $this->planDirectory.'/.latest.json';
    $pointer = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    unset($pointer['signature']);
    $pointer[$field] = $value;
    $pointer['signature'] = hash_hmac('sha256', Snapshot::canonical($pointer), file_get_contents($this->planDirectory.'/.key'));
    file_put_contents($path, json_encode($pointer, JSON_THROW_ON_ERROR));

    expect(fn () => $this->planStore->latest())->toThrow(RuntimeException::class);
})->with([['purpose', 'another-purpose'], ['format', 2]]);

it('refuses symlinks in the latest pointer trust chain', function (string $part): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Symlink creation requires privileges on Windows.');
    }
    $plan = $this->planStore->save(['sql' => 'CREATE TABLE current_table (id INT)']);
    $path = $this->planDirectory.'/'.match ($part) {
        'pointer', 'dangling-pointer' => '.latest.json',
        'key' => '.key',
        default => $plan['id'].'.plan.json',
    };
    rename($path, $path.'.original');
    symlink($part === 'dangling-pointer' ? $path.'.missing' : $path.'.original', $path);

    expect(fn () => $this->planStore->latest())->toThrow(RuntimeException::class);
})->with(['pointer', 'dangling-pointer', 'key', 'plan']);

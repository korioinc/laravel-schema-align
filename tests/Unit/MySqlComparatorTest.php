<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Schema\Comparison\MySqlComparator;
use Korioinc\SchemaAlign\Schema\Snapshot;

function mysqlColumn(array $overrides = []): array
{
    return array_replace(['type' => 'bigint unsigned', 'nullable' => true, 'default' => null, 'default_kind' => 'none', 'identity' => '', 'generated' => '', 'collation' => null, 'charset' => null, 'generation_expression' => '', 'on_update' => '', 'comment' => '', 'invisible' => false, 'unsigned' => true], $overrides);
}

function mysqlTable(array $overrides = []): array
{
    return array_replace(['schema' => '@database', 'name' => 'sample_values', 'columns' => ['id' => mysqlColumn()], 'constraints' => [], 'indexes' => [], 'engine' => 'InnoDB', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'row_format' => 'Dynamic', 'comment' => ''], $overrides);
}

function mysqlIndex(array $overrides = []): array
{
    return array_replace(['signature' => 'id-ascending', 'definition' => 'diagnostic only', 'method' => 'BTREE', 'keys' => [['column' => 'id', 'expression' => null, 'prefix' => null, 'order' => 'ASC']], 'include' => [], 'predicate' => null, 'unique' => false, 'nulls_not_distinct' => false, 'invisible' => false], $overrides);
}

function mysqlSnapshot(array $tables = [], array $platform = [], array $unsupported = [], array $migrations = []): Snapshot
{
    // Literal server observations from MySQL 8.4.11, independent of PHP's Unicode version.
    $lowercase = ['Ä' => 'ä', 'Σ' => 'σ', 'ΟΣ' => 'οσ', 'K' => 'k', 'İ' => 'i', 'prİmary' => 'primary'];
    $key = static fn (string $name): string => strtoupper(bin2hex($lowercase[$name] ?? strtolower($name)));
    foreach ($tables as &$table) {
        $table['identifier_key'] = $key($table['name']);
        foreach (['columns', 'indexes'] as $collection) {
            foreach ($table[$collection] as $name => &$object) {
                $object['identifier_key'] = $key((string) $name);
            }
            unset($object);
        }
    }
    unset($table);

    return new Snapshot([], array_replace(['engine' => 'mysql', 'server_series' => '8.0', 'sql_mode' => 'STRICT_TRANS_TABLES', 'lower_case_table_names' => 0, 'collation' => 'utf8mb4_unicode_ci', 'settings' => ['sql_mode' => 'STRICT_TRANS_TABLES', 'lower_case_table_names' => 0, 'character_set_server' => 'utf8mb4', 'collation_server' => 'utf8mb4_unicode_ci', 'default_storage_engine' => 'InnoDB'], 'database' => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']], $platform), $tables, $unsupported, $migrations);
}

it('creates a Laravel identity and primary key in one nontransactional statement', function (): void {
    $table = mysqlTable(['columns' => ['id' => mysqlColumn(['nullable' => false, 'identity' => 'd'])], 'constraints' => ['PRIMARY' => ['kind' => 'p', 'columns' => ['id'], 'backing_index' => 'PRIMARY', 'signature' => 'primary-id']]]);
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $table]), mysqlSnapshot());
    expect($result['errors'])->toBe([])->and($result['differences'])->toHaveCount(1);
    $op = $result['differences'][0];
    expect($op['automatic'])->toBeTrue()->and($op['transactional'])->toBeFalse()
        ->and($op['id'])->toMatch('/^[a-f0-9]{24}$/')->and($op['sql'])->toBe(['CREATE TABLE `sample_values` (`id` bigint unsigned NOT NULL AUTO_INCREMENT, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC'])
        ->and($op['after']['constraints'])->toEqual($table['constraints']);
});

it('adds nullable columns with explicit null semantics and quotes identifiers', function (): void {
    $before = mysqlTable(['name' => 'user`data']);
    $after = $before;
    $after['columns']['new`column'] = mysqlColumn();
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.user`data' => $after]), mysqlSnapshot(['@database.user`data' => $before]));
    expect($result['differences'][0]['sql'])->toBe(['ALTER TABLE `user``data` ADD COLUMN `new``column` bigint unsigned NULL']);
});

it('keeps column additions manual when an existing name differs only by case', function (string $existingName, string $desiredName): void {
    $before = mysqlTable(['columns' => [$existingName => mysqlColumn()]]);
    $after = mysqlTable(['columns' => [$desiredName => mysqlColumn(), 'note' => mysqlColumn()]]);

    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    $operations = array_column($result['differences'], null, 'name');

    expect($result['errors'])->toBe([])
        ->and($operations[$desiredName]['kind'])->toBe('add_column')
        ->and($operations[$desiredName]['automatic'])->toBeFalse()
        ->and($operations[$desiredName]['sql'])->toBe([])
        ->and($operations[$existingName]['kind'])->toBe('extra_column')
        ->and($operations[$existingName]['automatic'])->toBeFalse()
        ->and($operations['note']['automatic'])->toBeTrue()
        ->and($operations['note']['sql'])->toBe(['ALTER TABLE `sample_values` ADD COLUMN `note` bigint unsigned NULL']);
})->with([['email', 'Email'], ['Email', 'email'], ['Ä', 'ä'], ['ä', 'Ä'], ['Σ', 'σ'], ['ΟΣ', 'οσ'], ['K', 'k'], ['İ', 'i']]);

it('allows distinct MySQL column names without expanding Unicode case folds', function (string $existingName, string $desiredName): void {
    $before = mysqlTable(['columns' => [$existingName => mysqlColumn()]]);
    $after = $before;
    $after['columns'][$desiredName] = mysqlColumn();

    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));

    expect($result['errors'])->toBe([])
        ->and($result['differences'])->toHaveCount(1)
        ->and($result['differences'][0]['automatic'])->toBeTrue();
})->with([['Σ', 'ς'], ['ΟΣ', 'ος'], ['ß', 'ss'], ['ß', 'ẞ'], ['ſ', 's'], ['é', 'e']]);

it('compares identical snapshots with distinct MySQL Unicode identifiers', function (string $collection): void {
    $object = $collection === 'columns' ? mysqlColumn() : mysqlIndex();
    $snapshot = mysqlSnapshot(['@database.sample_values' => mysqlTable([$collection => ['ß' => $object, 'ẞ' => $object]])]);

    $comparison = (new MySqlComparator)->compare($snapshot, $snapshot);

    expect($comparison['errors'])->toBe([])->and($comparison['differences'])->toBe([]);
})->with(['columns', 'indexes']);

it('uses the configured MySQL table name namespace for additions', function (int $setting): void {
    $platform = mysqlSnapshot()->platform;
    $platform['settings']['lower_case_table_names'] = $setting;
    $before = mysqlSnapshot(['@database.sample_values' => mysqlTable()], $platform);
    $after = mysqlSnapshot(['@database.Sample_values' => mysqlTable(['name' => 'Sample_values', 'indexes' => ['new_index' => mysqlIndex()]])], $platform);

    $comparison = (new MySqlComparator)->compare($after, $before);
    $operations = array_column($comparison['differences'], null, 'kind');

    expect($comparison['errors'])->toBe([])
        ->and($operations['create_table']['automatic'])->toBe($setting === 0)
        ->and($operations['create_table']['sql'] !== [])->toBe($setting === 0)
        ->and($operations['create_index']['automatic'])->toBe($setting === 0)
        ->and($operations['create_index']['sql'] !== [])->toBe($setting === 0)
        ->and($operations['extra_table']['automatic'])->toBeFalse();
})->with([0, 1, 2]);

it('rejects Unicode case collisions inside a MySQL table namespace', function (string $collection): void {
    $object = $collection === 'columns' ? mysqlColumn() : mysqlIndex();
    $snapshot = mysqlSnapshot(['@database.sample_values' => mysqlTable([$collection => ['Ä' => $object, 'ä' => $object]])]);

    expect((new MySqlComparator)->compare($snapshot, mysqlSnapshot())['errors'])->not->toBeEmpty();
})->with(['columns', 'indexes']);

it('keeps legacy Unicode snapshot changes manual without rejecting identical snapshots', function (): void {
    $before = new Snapshot([], mysqlSnapshot()->platform, ['@database.sample_values' => mysqlTable(['columns' => ['ß' => mysqlColumn(), 'ẞ' => mysqlColumn()]])]);
    $after = clone $before;
    $after->tables['@database.sample_values']['columns']['é'] = mysqlColumn();
    $comparator = new MySqlComparator;

    expect($comparator->compare($before, $before)['errors'])->toBe([])
        ->and($comparator->compare($before, $before)['differences'])->toBe([]);
    $comparison = $comparator->compare($after, $before);
    expect($comparison['errors'])->toBe([])
        ->and($comparison['differences'][0]['automatic'])->toBeFalse()
        ->and($comparison['differences'][0]['sql'])->toBe([]);
});

it('keeps legacy ASCII snapshot additions automatic', function (): void {
    $before = new Snapshot([], mysqlSnapshot()->platform, ['@database.sample_values' => mysqlTable()]);
    $after = clone $before;
    $after->tables['@database.sample_values']['columns']['note'] = mysqlColumn();
    $comparison = (new MySqlComparator)->compare($after, $before);

    expect($comparison['errors'])->toBe([])
        ->and($comparison['differences'][0]['automatic'])->toBeTrue();
});

it('uses MySQL identifier equality for the reserved PRIMARY index name', function (string $change): void {
    $before = mysqlTable(['indexes' => $change === 'rename' ? ['old' => mysqlIndex()] : []]);
    $after = mysqlTable(['indexes' => ['prİmary' => mysqlIndex(['unique' => $change === 'new_table'])]]);
    $comparison = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot($change === 'new_table' ? [] : ['@database.sample_values' => $before]));
    $operations = array_column($comparison['differences'], null, 'name');

    expect($comparison['errors'])->toBe([])
        ->and($operations['prİmary']['automatic'])->toBeFalse()
        ->and($operations['prİmary']['sql'])->toBe([]);
    foreach ($comparison['differences'] as $operation) {
        expect(implode(' ', $operation['sql']))->not->toContain('prİmary');
    }
})->with(['add', 'rename', 'new_table']);

it('renames semantic indexes and unique indexes in their table local namespace', function (): void {
    $before = mysqlTable(['indexes' => ['old' => mysqlIndex(['unique' => true])]]);
    $after = mysqlTable(['indexes' => ['new' => mysqlIndex(['unique' => true, 'definition' => 'different diagnostic'])]]);
    $other = mysqlTable(['name' => 'other', 'indexes' => ['new' => mysqlIndex()]]);
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after, '@database.other' => $other]), mysqlSnapshot(['@database.sample_values' => $before, '@database.other' => $other]));
    expect($result['errors'])->toBe([])->and($result['differences'])->toHaveCount(1)
        ->and($result['differences'][0]['old_name'])->toBe('old')
        ->and($result['differences'][0]['sql'])->toBe(['ALTER TABLE `sample_values` RENAME INDEX `old` TO `new`']);
});

it('keeps unique additions changes and extras manual', function (): void {
    $before = mysqlTable(['indexes' => ['existing' => mysqlIndex()]]);
    $after = mysqlTable(['indexes' => ['unique_id' => mysqlIndex(['unique' => true, 'signature' => 'unique-id'])]]);
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    expect(array_column($result['differences'], 'automatic'))->toBe([false, false]);
    $after['indexes'] = ['existing' => mysqlIndex(['signature' => 'changed'])];
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    expect($result['differences'][0]['kind'])->toBe('change_index')->and($result['differences'][0]['sql'])->toBe([]);
});

it('fails closed for ambiguous index rename candidates', function (): void {
    $before = mysqlTable(['indexes' => ['a' => mysqlIndex(), 'b' => mysqlIndex()]]);
    $after = mysqlTable(['indexes' => ['c' => mysqlIndex()]]);
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    expect($result['errors'])->not->toBeEmpty()->and(array_unique(array_column($result['differences'], 'automatic')))->toBe([false]);
});

it('ignores server patch versions but blocks platform drift and unsupported objects', function (): void {
    $comparator = new MySqlComparator;
    expect($comparator->compare(mysqlSnapshot([], ['server_version' => '8.0.40']), mysqlSnapshot([], ['server_version' => '8.0.41']))['errors'])->toBe([]);
    foreach (['engine' => 'mariadb', 'server_series' => '8.4', 'sql_mode' => '', 'lower_case_table_names' => 1, 'collation' => 'other'] as $field => $value) {
        expect($comparator->compare(mysqlSnapshot(), mysqlSnapshot([], [$field => $value]))['errors'])->not->toBeEmpty();
    }
    expect($comparator->compare(mysqlSnapshot([], [], ['partitioning']), mysqlSnapshot())['errors'])->not->toBeEmpty();
});

it('treats table options and full column metadata as meaningful', function (): void {
    $before = mysqlTable();
    $after = mysqlTable(['engine' => 'MyISAM']);
    $after['columns']['id']['comment'] = 'changed';
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    expect(array_column($result['differences'], 'kind'))->toBe(['change_column', 'change_table'])
        ->and(array_column($result['differences'], 'automatic'))->toBe([false, false]);
});

it('keeps indexes automatic alongside unrelated manual column changes', function (string $change, array $keys): void {
    $before = mysqlTable(['columns' => ['email' => mysqlColumn(['type' => 'varchar(255)', 'unsigned' => false]), 'tenant`id' => mysqlColumn()]]);
    $after = $before;

    if ($change === 'change') {
        $before['columns']['required_name'] = mysqlColumn(['type' => 'int unsigned']);
    }
    $after['columns']['required_name'] = mysqlColumn(['nullable' => false]);
    $after['indexes']['lookup'] = mysqlIndex(['keys' => $keys]);

    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    $operations = array_column($result['differences'], null, 'name');

    expect($operations['required_name']['automatic'])->toBeFalse()
        ->and($operations['lookup']['automatic'])->toBeTrue()
        ->and($operations['lookup']['dependencies'])->toBe([]);
})->with(['addition', 'change'])->with([
    'existing key' => [[['column' => 'email', 'expression' => null, 'prefix' => null, 'order' => 'ASC']]],
    'quoted key' => [[['column' => 'tenant`id', 'expression' => null, 'prefix' => null, 'order' => 'DESC']]],
    'multiple prefix keys' => [[['column' => 'email', 'expression' => null, 'prefix' => 32, 'order' => 'DESC'], ['column' => 'tenant`id', 'expression' => null, 'prefix' => null, 'order' => 'ASC']]],
]);

it('blocks an index dependent on a manual key column change', function (string $change): void {
    $before = mysqlTable();
    $after = mysqlTable(['indexes' => ['new_index' => mysqlIndex(['keys' => [['column' => 'required', 'expression' => null, 'prefix' => null, 'order' => 'DESC']]])]]);

    if ($change === 'change') {
        $before['columns']['required'] = mysqlColumn(['type' => 'int unsigned']);
    }
    $after['columns']['required'] = mysqlColumn(['nullable' => false]);
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    expect($result['differences'][1]['automatic'])->toBeFalse()
        ->and($result['differences'][1]['dependencies'])->toBe([$result['differences'][0]['id']]);
})->with(['addition', 'change']);

it('depends only on new index key columns alongside an unrelated required addition', function (): void {
    $before = mysqlTable();
    $after = $before;
    $after['columns']['required_name'] = mysqlColumn(['nullable' => false]);
    $after['columns']['email`address'] = mysqlColumn(['type' => 'varchar(255)', 'unsigned' => false]);
    $after['columns']['tenant_id'] = mysqlColumn();
    $after['indexes']['lookup'] = mysqlIndex(['keys' => [['column' => 'email`address', 'expression' => null, 'prefix' => 32, 'order' => 'DESC'], ['column' => 'tenant_id', 'expression' => null, 'prefix' => null, 'order' => 'ASC']]]);

    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    $operations = array_column($result['differences'], null, 'name');

    expect($operations['lookup']['automatic'])->toBeTrue()
        ->and($operations['lookup']['dependencies'])->toEqualCanonicalizing([$operations['email`address']['id'], $operations['tenant_id']['id']]);
});

it('keeps indexes dependent on manual table option changes', function (): void {
    $before = mysqlTable();
    $after = mysqlTable(['comment' => 'changed', 'indexes' => ['lookup' => mysqlIndex()]]);

    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    $operations = array_column($result['differences'], null, 'kind');

    expect($operations['create_index']['automatic'])->toBeFalse()
        ->and($operations['create_index']['dependencies'])->toBe([$operations['change_table']['id']]);
});

it('builds ordinary indexes from structured ordered prefix keys instead of diagnostic SQL', function (): void {
    $before = mysqlTable(['columns' => ['email' => mysqlColumn(['type' => 'varchar(255)', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'unsigned' => false])]]);
    $after = $before;
    $after['indexes']['email_lookup'] = mysqlIndex(['definition' => 'DROP TABLE sample_values; malicious diagnostic', 'keys' => [['column' => 'email', 'expression' => null, 'prefix' => 32, 'order' => 'DESC']]]);
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    expect($result['differences'][0]['sql'])->toBe(['CREATE INDEX `email_lookup` ON `sample_values` (`email`(32) DESC)']);
});

it('includes simple unique indexes in a new table without duplicate changes', function (): void {
    $table = mysqlTable(['indexes' => ['unique_id' => mysqlIndex(['unique' => true])]]);
    $desired = mysqlSnapshot(['@database.sample_values' => $table]);
    $result = (new MySqlComparator)->compare($desired, mysqlSnapshot());
    expect($result['differences'])->toHaveCount(1)->and($result['differences'][0]['automatic'])->toBeTrue()
        ->and($result['differences'][0]['sql'][0])->toContain('UNIQUE KEY `unique_id` (`id` ASC)')
        ->and($result['differences'][0]['after']['indexes'])->toEqual($desired->tables['@database.sample_values']['indexes']);
});

it('keeps foreign keys separate and manual on new tables', function (): void {
    $table = mysqlTable(['constraints' => ['parent_fk' => ['kind' => 'f', 'columns' => ['id'], 'signature' => 'foreign-id', 'backing_index' => null]]]);
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $table]), mysqlSnapshot());
    $byKind = array_column($result['differences'], null, 'kind');
    expect($byKind['create_table']['automatic'])->toBeTrue()->and($byKind['create_table']['after']['constraints'])->toBe([])
        ->and($byKind['add_constraint']['automatic'])->toBeFalse()->and($byKind['add_constraint']['dependencies'])->toBe([$byKind['create_table']['id']]);
});

it('requires manual review for unsafe column attributes', function (array $attributes): void {
    $before = mysqlTable();
    $after = $before;
    $after['columns']['addition'] = mysqlColumn($attributes);
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    expect($result['differences'][0]['automatic'])->toBeFalse()->and($result['differences'][0]['sql'])->toBe([]);
})->with([
    'literal default' => [['default' => '0', 'default_kind' => 'literal']],
    'expression default' => [['type' => 'timestamp', 'default' => 'CURRENT_TIMESTAMP', 'default_kind' => 'expression']],
    'generated' => [['generated' => 's', 'generation_expression' => '1+1']],
    'auto increment' => [['nullable' => false, 'identity' => 'd']],
    'collation override' => [['type' => 'varchar(255)', 'collation' => 'utf8mb4_bin']],
    'on update' => [['on_update' => 'CURRENT_TIMESTAMP']],
    'invisible' => [['invisible' => true]],
    'hostile type' => [['type' => 'bigint; DROP TABLE sample_values']],
]);

it('does not infer table or column renames and reports missing migration history', function (): void {
    $before = mysqlTable();
    $after = mysqlTable(['columns' => ['renamed' => mysqlColumn()]]);
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before], [], [], ['old_migration']));
    expect(array_column($result['differences'], 'kind'))->toBe(['add_column', 'extra_column'])
        ->and($result['warnings'])->not->toBeEmpty()->and($result['historical_missing_files'])->toBe(['old_migration']);
    $after['name'] = 'renamed_sample_values';
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.renamed_sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    expect(array_column($result['differences'], 'kind'))->toBe(['create_table', 'extra_table'])->and($result['warnings'])->not->toBeEmpty();
});

it('ignores physical column order and keeps declaration differences manual', function (): void {
    $before = mysqlTable(['columns' => ['a' => mysqlColumn(), 'b' => mysqlColumn()]]);
    $after = mysqlTable(['columns' => array_reverse($before['columns'], true)]);
    $comparator = new MySqlComparator;
    expect($comparator->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]))['differences'])->toBe([]);
    $after['columns']['a']['type'] = 'int unsigned';
    expect($comparator->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]))['differences'][0]['automatic'])->toBeFalse();
});

it('blocks case insensitive index namespace collisions within a table', function (): void {
    $table = mysqlTable(['indexes' => ['lookup' => mysqlIndex(), 'LOOKUP' => mysqlIndex(['signature' => 'different'])]]);
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $table]), mysqlSnapshot());
    expect($result['errors'])->not->toBeEmpty()->and($result['differences'])->toBe([]);
});

it('requires complete platform safety settings even when both snapshots omit them', function (): void {
    $snapshot = new Snapshot([], ['engine' => 'mysql', 'server_series' => '8.4'], []);
    expect((new MySqlComparator)->compare($snapshot, $snapshot)['errors'])->not->toBeEmpty();
});

it('blocks inherited collation additions while table defaults require manual change', function (): void {
    $before = mysqlTable();
    $after = mysqlTable(['collation' => 'utf8mb4_bin']);
    $after['columns']['label'] = mysqlColumn(['type' => 'varchar(255)', 'unsigned' => false, 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin']);
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    expect(array_column($result['differences'], 'automatic'))->toBe([false, false]);
});

it('does not automatically create indexes requiring unsupported storage semantics', function (string $type): void {
    $before = mysqlTable(['columns' => ['id' => mysqlColumn(['type' => $type])]]);
    $after = $before;
    $after['indexes']['lookup'] = mysqlIndex();
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    expect($result['differences'][0]['automatic'])->toBeFalse();
})->with(['text', 'blob', 'json']);

it('blocks rename targets occupied by differently cased indexes scheduled for manual removal', function (): void {
    $before = mysqlTable(['indexes' => ['old' => mysqlIndex(), 'NEW' => mysqlIndex(['signature' => 'other', 'unique' => true])]]);
    $after = mysqlTable(['indexes' => ['new' => mysqlIndex()]]);
    $result = (new MySqlComparator)->compare(mysqlSnapshot(['@database.sample_values' => $after]), mysqlSnapshot(['@database.sample_values' => $before]));
    $operations = array_column($result['differences'], null, 'kind');
    expect($operations['rename_index']['automatic'])->toBeFalse()->and($operations['rename_index']['sql'])->toBe([]);
});

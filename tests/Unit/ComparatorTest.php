<?php

declare(strict_types=1);

use Korioinc\SchemaAlign\Schema\Comparison\SchemaComparator;
use Korioinc\SchemaAlign\Schema\Snapshot;

function comparatorSnapshot(array $tables = [], array $platform = ['major' => 16], array $unsupported = []): Snapshot
{
    return new Snapshot([], $platform, $tables, $unsupported);
}

function comparatorTable(array $columns = [], array $indexes = [], array $constraints = [], string $name = 'sample_values'): array
{
    return ['schema' => 'public', 'name' => $name, 'columns' => $columns, 'indexes' => $indexes, 'constraints' => $constraints];
}

function comparatorColumn(string $type = 'text', bool $nullable = true): array
{
    return ['type' => $type, 'nullable' => $nullable, 'default' => null, 'identity' => '', 'generated' => '', 'collation' => null];
}

function comparatorIndex(string $name, string $signature = 'email-index'): array
{
    return ['signature' => $signature, 'definition' => 'CREATE INDEX '.$name.' ON public.sample_values USING btree (email)', 'method' => 'btree', 'keys' => ['email'], 'include' => [], 'predicate' => null, 'unique' => false, 'nulls_not_distinct' => false];
}

it('reports no drift for equal semantic snapshots regardless of map order', function (): void {
    $a = comparatorSnapshot(['public.sample_values' => comparatorTable(['id' => comparatorColumn('integer'), 'email' => comparatorColumn()])]);
    $b = comparatorSnapshot(['public.sample_values' => comparatorTable(['email' => comparatorColumn(), 'id' => comparatorColumn('integer')])]);
    expect((new SchemaComparator)->compare($a, $b)['differences'])->toBe([]);
});

it('automates nullable additions and quotes identifiers', function (): void {
    $desired = comparatorSnapshot(['public.sample_values' => comparatorTable(['odd"column' => comparatorColumn()])]);
    $actual = comparatorSnapshot(['public.sample_values' => comparatorTable()]);
    $result = (new SchemaComparator)->compare($desired, $actual);
    expect($result['differences'][0]['automatic'])->toBeTrue()
        ->and($result['differences'][0]['sql'])->toBe(['ALTER TABLE "public"."sample_values" ADD COLUMN "odd""column" text']);
});

it('distinguishes index naming drift from semantic drift', function (): void {
    $desired = comparatorSnapshot(['public.sample_values' => comparatorTable([], ['new_name' => comparatorIndex('new_name')])]);
    $actual = comparatorSnapshot(['public.sample_values' => comparatorTable([], ['old_name' => comparatorIndex('old_name')])]);
    $result = (new SchemaComparator)->compare($desired, $actual);
    expect($result['differences'][0]['old_name'])->toBe('old_name')
        ->and($result['differences'][0]['kind'])->toBe('rename_index')
        ->and($result['differences'][0]['sql'])->toBe(['ALTER INDEX "public"."old_name" RENAME TO "new_name"']);
    $actual->tables['public.sample_values']['indexes']['new_name'] = comparatorIndex('new_name', 'different');
    unset($actual->tables['public.sample_values']['indexes']['old_name']);
    $result = (new SchemaComparator)->compare($desired, $actual);
    expect($result['differences'][0]['automatic'])->toBeFalse()->and($result['differences'][0]['sql'])->toBe([]);
});

it('blocks ambiguous names and relation namespace collisions', function (): void {
    $desired = comparatorSnapshot(['public.sample_values' => comparatorTable([], ['target' => comparatorIndex('target')])]);
    $actual = comparatorSnapshot(['public.sample_values' => comparatorTable([], ['one' => comparatorIndex('one'), 'two' => comparatorIndex('two')])]);
    $result = (new SchemaComparator)->compare($desired, $actual);
    expect($result['errors'])->not->toBeEmpty();
    foreach ($result['differences'] as $difference) {
        expect($difference['automatic'])->toBeFalse();
    }
    $actual = comparatorSnapshot(['public.sample_values' => comparatorTable([], ['old' => comparatorIndex('old')]), 'public.target' => comparatorTable(name: 'target')]);
    $result = (new SchemaComparator)->compare($desired, $actual);
    expect(array_values(array_filter($result['differences'], fn (array $d): bool => $d['kind'] === 'rename_index'))[0]['automatic'])->toBeFalse();
});

it('reserves excluded ledger table names without requiring matching ledger metadata', function (): void {
    $desired = comparatorSnapshot(['public.sample_values' => comparatorTable([], ['migrations' => comparatorIndex('migrations')])]);
    $actual = comparatorSnapshot(['public.sample_values' => comparatorTable()], ['major' => 16, 'excluded_relations' => [
        'public.migrations' => ['schema' => 'public', 'name' => 'migrations'],
    ]]);
    $result = (new SchemaComparator)->compare($desired, $actual);

    expect($result['errors'])->toBe([])
        ->and($result['differences'])->toHaveCount(1)
        ->and($result['differences'][0]['automatic'])->toBeFalse()
        ->and($result['differences'][0]['sql'])->toBe([]);
});

it('fails closed on unsupported objects and incompatible platforms', function (): void {
    $comparator = new SchemaComparator;
    $desired = comparatorSnapshot(['public.sample_values' => comparatorTable(['id' => comparatorColumn('integer')])]);
    foreach ([comparatorSnapshot(platform: ['major' => 17]), comparatorSnapshot(unsupported: ['partitioned table'])] as $actual) {
        $result = $comparator->compare($desired, $actual);
        expect($result['errors'])->not->toBeEmpty()->and($result['differences'])->toBe([]);
    }
});

it('builds ordinary new tables with keys and dependent indexes', function (): void {
    $primary = ['kind' => 'p', 'definition' => 'PRIMARY KEY (id)', 'signature' => 'primary-id', 'columns' => ['id'], 'backing_index' => 'sample_values_pkey'];
    $desired = comparatorSnapshot(['public.sample_values' => comparatorTable(
        ['id' => comparatorColumn('integer', false), 'email' => comparatorColumn()],
        ['sample_values_email' => comparatorIndex('sample_values_email')],
        ['sample_values_pkey' => $primary],
    )]);
    $result = (new SchemaComparator)->compare($desired, comparatorSnapshot());
    expect($result['errors'])->toBe([])->and($result['differences'])->toHaveCount(3);
    $table = array_values(array_filter($result['differences'], fn (array $d): bool => $d['kind'] === 'create_table'))[0];
    expect($table['after']['constraints'])->toBe([])->and($table['after']['indexes'])->toBe([]);
    foreach ($result['differences'] as $difference) {
        expect($difference['automatic'])->toBeTrue();

        if ($difference['kind'] !== 'create_table') {
            expect($difference['dependencies'])->toContain($table['id']);
        }
    }
});

it('renames constraints once and checks their backing index target', function (): void {
    $key = ['kind' => 'u', 'definition' => 'UNIQUE (email)', 'signature' => 'unique-email', 'columns' => ['email'], 'backing_index' => 'old_key'];
    $newKey = [...$key, 'backing_index' => 'new_key'];
    $desired = comparatorSnapshot(['public.sample_values' => comparatorTable(constraints: ['new_key' => $newKey])]);
    $actual = comparatorSnapshot(['public.sample_values' => comparatorTable(constraints: ['old_key' => $key])]);
    $result = (new SchemaComparator)->compare($desired, $actual);
    expect($result['differences'])->toHaveCount(1)
        ->and($result['differences'][0]['old_name'])->toBe('old_key')
        ->and($result['differences'][0]['sql'])->toBe(['ALTER TABLE "public"."sample_values" RENAME CONSTRAINT "old_key" TO "new_key"']);
    $actual->tables['public.new_key'] = comparatorTable(name: 'new_key');
    $result = (new SchemaComparator)->compare($desired, $actual);
    $rename = array_values(array_filter($result['differences'], fn (array $d): bool => $d['kind'] === 'rename_constraint'))[0];
    expect($rename['automatic'])->toBeFalse()->and($rename['sql'])->toBe([]);
});

it('requires review for unique and expression indexes and existing constraints', function (): void {
    $index = comparatorIndex('email_idx');
    $expression = [...$index, 'definition' => 'CREATE INDEX expression_idx ON public.sample_values USING btree (lower(email))'];
    $key = ['kind' => 'u', 'definition' => 'UNIQUE (email)', 'signature' => 'unique-email', 'columns' => ['email'], 'backing_index' => 'email_key'];
    $desired = comparatorSnapshot(['public.sample_values' => comparatorTable(['email' => comparatorColumn()], ['email_idx' => [...$index, 'unique' => true], 'expression_idx' => $expression], ['email_key' => $key])]);
    $actual = comparatorSnapshot(['public.sample_values' => comparatorTable(['email' => comparatorColumn()])]);
    foreach ((new SchemaComparator)->compare($desired, $actual)['differences'] as $difference) {
        expect($difference['automatic'])->toBeFalse()->and($difference['sql'])->toBe([]);
    }
});

it('keeps operation ids stable across input ordering and reports historical migration files', function (): void {
    $actual = comparatorSnapshot(['public.sample_values' => comparatorTable()]);
    $desired = comparatorSnapshot(['public.sample_values' => comparatorTable(['a' => comparatorColumn(), 'b' => comparatorColumn()])]);
    $desiredReordered = comparatorSnapshot(['public.sample_values' => comparatorTable(['b' => comparatorColumn(), 'a' => comparatorColumn()])]);
    $actual->migrationNames = ['historical', 'present'];
    $desired->migrationNames = $desiredReordered->migrationNames = ['present'];
    $comparator = new SchemaComparator;
    expect($comparator->compare($desired, $actual))->toBe($comparator->compare($desiredReordered, $actual))
        ->and($comparator->compare($desired, $actual)['historical_missing_files'])->toBe(['historical']);
});

it('compares major versions while enforcing extensions and settings', function (): void {
    $desired = comparatorSnapshot(platform: ['server_major' => 16, 'server_version_num' => 160005, 'server_version' => '16.5', 'extensions' => [], 'settings' => ['timezone' => 'UTC']]);
    $actual = comparatorSnapshot(platform: ['server_major' => 16, 'server_version_num' => 160006, 'server_version' => '16.6', 'extensions' => [], 'settings' => ['timezone' => 'UTC']]);
    expect((new SchemaComparator)->compare($desired, $actual)['errors'])->toBe([]);
    $actual->platform['settings']['timezone'] = 'Asia/Seoul';
    expect((new SchemaComparator)->compare($desired, $actual)['errors'])->not->toBeEmpty();
});

it('orders foreign key creation after tables and their unique keys', function (): void {
    $pk = ['kind' => 'p', 'definition' => 'PRIMARY KEY (id)', 'signature' => 'pk-id', 'columns' => ['id'], 'backing_index' => 'parents_pkey'];
    $fk = ['kind' => 'f', 'definition' => 'FOREIGN KEY (parent_id) REFERENCES public.parents(id)', 'signature' => 'fk-parent', 'columns' => ['parent_id'], 'backing_index' => null];
    // pg_get_constraintdef does not put a space before the referenced column list.
    $desired = comparatorSnapshot([
        'public.parents' => comparatorTable(['id' => comparatorColumn('integer', false)], constraints: ['parents_pkey' => $pk], name: 'parents'),
        'public.children' => comparatorTable(['parent_id' => comparatorColumn('integer')], constraints: ['parent_fk' => $fk], name: 'children'),
    ]);
    $result = (new SchemaComparator)->compare($desired, comparatorSnapshot());
    $fkOp = array_values(array_filter($result['differences'], fn (array $d): bool => $d['name'] === 'parent_fk'))[0];
    expect($fkOp['automatic'])->toBeTrue()->and($fkOp['dependencies'])->toHaveCount(3);
});

it('requires a matching standalone unique index before adding a foreign key', function (string $state): void {
    $index = [...comparatorIndex('parents_email_unique'), 'unique' => true,
        'definition' => 'CREATE UNIQUE INDEX parents_email_unique ON public.parents USING btree (email)'];
    $parent = comparatorTable(['email' => comparatorColumn()], ['parents_email_unique' => $index], name: 'parents');
    $foreign = ['kind' => 'f', 'definition' => 'FOREIGN KEY (email) REFERENCES public.parents(email)',
        'signature' => 'fk-parent-email', 'columns' => ['email'], 'backing_index' => null];
    $desired = comparatorSnapshot([
        'public.parents' => $parent,
        'public.children' => comparatorTable(['email' => comparatorColumn()], constraints: ['parent_fk' => $foreign], name: 'children'),
    ]);
    $indexes = $state === 'missing' ? [] : $parent['indexes'];

    if ($state === 'nonunique') {
        $indexes['parents_email_unique']['unique'] = false;
        $indexes['parents_email_unique']['signature'] = 'nonunique-email';
        $indexes['parents_email_unique']['definition'] = 'CREATE INDEX parents_email_unique ON public.parents USING btree (email)';
    }
    $actual = comparatorSnapshot(['public.parents' => [...$parent, 'indexes' => $indexes]]);
    $result = (new SchemaComparator)->compare($desired, $actual);
    $foreignOperation = array_values(array_filter($result['differences'], fn (array $operation): bool => $operation['name'] === 'parent_fk'))[0];

    expect($foreignOperation['automatic'])->toBe($state === 'existing');

    if ($state !== 'existing') {
        $indexOperation = array_values(array_filter($result['differences'], fn (array $operation): bool => $operation['name'] === 'parents_email_unique'))[0];
        expect($indexOperation['automatic'])->toBeFalse()
            ->and($foreignOperation['sql'])->toBe([])
            ->and($foreignOperation['dependencies'])->toContain($indexOperation['id']);
    }
})->with(['missing', 'existing', 'nonunique']);

it('keeps foreign keys automatic when an existing key supports the reference', function (string $location, string $state, string $existingKey): void {
    $primary = ['kind' => 'p', 'definition' => 'PRIMARY KEY (id)', 'signature' => 'primary-id',
        'columns' => ['id'], 'backing_index' => 'parents_pkey'];
    $unique = [...comparatorIndex('parents_id_unique', 'unique-id'), 'unique' => true,
        'keys' => [['column' => 'id']], 'definition' => 'CREATE UNIQUE INDEX parents_id_unique ON public.parents USING btree (id)'];
    $parent = comparatorTable(['id' => comparatorColumn('integer', false), 'email' => comparatorColumn()],
        indexes: $existingKey === 'index' ? ['parents_id_unique' => $unique] : [],
        constraints: $existingKey === 'primary' ? ['parents_pkey' => $primary] : [], name: 'parents');
    $other = comparatorTable(['id' => comparatorColumn('integer'), 'email' => comparatorColumn()], name: 'other');
    $actual = comparatorSnapshot(['public.parents' => $parent, 'public.other' => $other]);
    $desired = comparatorSnapshot($actual->tables);
    $table = $location === 'other table' ? 'other' : 'parents';
    $column = $location === 'other columns' ? 'email' : 'id';
    $index = [...comparatorIndex('additional_unique', 'additional-unique'), 'unique' => true,
        'keys' => [['column' => $column]],
        'definition' => 'CREATE UNIQUE INDEX additional_unique ON public.'.$table.' USING btree ('.$column.')'];
    $desired->tables['public.'.$table]['indexes']['additional_unique'] = $index;

    if ($state === 'nonunique') {
        $actual->tables['public.'.$table]['indexes']['additional_unique'] = [...$index,
            'unique' => false, 'signature' => 'additional-nonunique',
            'definition' => 'CREATE INDEX additional_unique ON public.'.$table.' USING btree ('.$column.')'];
    }
    $foreign = ['kind' => 'f', 'definition' => 'FOREIGN KEY (parent_id) REFERENCES public.parents(id)',
        'signature' => 'fk-parent-id', 'columns' => ['parent_id'], 'backing_index' => null];
    $desired->tables['public.children'] = comparatorTable(['parent_id' => comparatorColumn('integer')],
        constraints: ['parent_fk' => $foreign], name: 'children');

    $result = (new SchemaComparator)->compare($desired, $actual);
    $foreignOperation = array_values(array_filter($result['differences'], fn (array $operation): bool => $operation['name'] === 'parent_fk'))[0];
    $indexOperation = array_values(array_filter($result['differences'], fn (array $operation): bool => $operation['name'] === 'additional_unique'))[0];

    expect($indexOperation['automatic'])->toBeFalse()
        ->and($foreignOperation['automatic'])->toBeTrue()
        ->and($foreignOperation['sql'])->not->toBeEmpty()
        ->and($foreignOperation['dependencies'])->not->toContain($indexOperation['id']);
})->with(['other table', 'other columns', 'same columns'])->with(['missing', 'nonunique'])->with(['primary', 'index']);

it('requires only referenced column changes before reusing an existing foreign key target', function (string $changedColumn): void {
    $primary = ['kind' => 'p', 'definition' => 'PRIMARY KEY (id)', 'signature' => 'primary-id',
        'columns' => ['id'], 'backing_index' => 'parents_pkey'];
    $parent = comparatorTable(['id' => comparatorColumn('integer', false), 'email' => comparatorColumn()],
        constraints: ['parents_pkey' => $primary], name: 'parents');
    $actual = comparatorSnapshot(['public.parents' => $parent]);
    $actual->tables['public.parents']['columns'][$changedColumn] = comparatorColumn('text', false);
    $parent['columns'][$changedColumn] = comparatorColumn('integer', false);
    $foreign = ['kind' => 'f', 'definition' => 'FOREIGN KEY (parent_id) REFERENCES public.parents(id)',
        'signature' => 'fk-parent-id', 'columns' => ['parent_id'], 'backing_index' => null];
    $desired = comparatorSnapshot([
        'public.parents' => $parent,
        'public.children' => comparatorTable(['parent_id' => comparatorColumn('integer')], constraints: ['parent_fk' => $foreign], name: 'children'),
    ]);
    $result = (new SchemaComparator)->compare($desired, $actual);
    $operations = array_column($result['differences'], null, 'name');

    expect($operations[$changedColumn]['automatic'])->toBeFalse()
        ->and($operations['parent_fk']['automatic'])->toBe($changedColumn !== 'id');

    if ($changedColumn === 'id') {
        expect($operations['parent_fk']['dependencies'])->toContain($operations['id']['id'])
            ->and($operations['parent_fk']['sql'])->toBe([]);
    } else {
        expect($operations['parent_fk']['dependencies'])->not->toContain($operations['email']['id']);
    }
})->with(['id', 'email']);

it('matches quoted composite foreign keys to the supplying unique index', function (): void {
    $columns = ['account, id' => comparatorColumn('integer'), 'external"id' => comparatorColumn('integer'), 'email' => comparatorColumn()];
    $index = [...comparatorIndex('parent_key'), 'unique' => true,
        'keys' => [['column' => 'external"id'], ['column' => 'account, id']], 'include' => ['email'],
        'definition' => 'CREATE UNIQUE INDEX parent_key ON public."odd""parents" USING btree ("external""id", "account, id") INCLUDE (email)'];
    $parent = comparatorTable($columns, ['parent_key' => $index], name: 'odd"parents');
    $foreign = ['kind' => 'f', 'definition' => 'FOREIGN KEY ("account, id", "external""id") REFERENCES public."odd""parents"("account, id", "external""id")',
        'signature' => 'fk-composite', 'columns' => ['account, id', 'external"id'], 'backing_index' => null];
    $desired = comparatorSnapshot([
        'public.odd"parents' => $parent,
        'public.children' => comparatorTable($columns, constraints: ['parent_fk' => $foreign], name: 'children'),
    ]);
    $actual = comparatorSnapshot(['public.odd"parents' => [...$parent, 'indexes' => []]]);
    $result = (new SchemaComparator)->compare($desired, $actual);
    $foreignOperation = array_values(array_filter($result['differences'], fn (array $operation): bool => $operation['name'] === 'parent_fk'))[0];
    $indexOperation = array_values(array_filter($result['differences'], fn (array $operation): bool => $operation['name'] === 'parent_key'))[0];

    expect($foreignOperation['automatic'])->toBeFalse()
        ->and($foreignOperation['dependencies'])->toContain($indexOperation['id']);
});

it('compares numeric-looking referenced column names as identifiers', function (): void {
    $index = [...comparatorIndex('numeric_key'), 'unique' => true,
        'keys' => [['column' => '02'], ['column' => '2']],
        'definition' => 'CREATE UNIQUE INDEX numeric_key ON public.parents USING btree ("02", "2")'];
    $parent = comparatorTable(['02' => comparatorColumn('integer'), '2' => comparatorColumn('integer')],
        ['numeric_key' => $index], name: 'parents');
    $foreign = ['kind' => 'f', 'definition' => 'FOREIGN KEY (first_id, second_id) REFERENCES public.parents("2", "02")',
        'signature' => 'fk-numeric', 'columns' => ['first_id', 'second_id'], 'backing_index' => null];
    $desired = comparatorSnapshot([
        'public.parents' => $parent,
        'public.children' => comparatorTable(['first_id' => comparatorColumn('integer'), 'second_id' => comparatorColumn('integer')],
            constraints: ['parent_fk' => $foreign], name: 'children'),
    ]);
    $result = (new SchemaComparator)->compare($desired, comparatorSnapshot(['public.parents' => [...$parent, 'indexes' => []]]));
    $operations = array_column($result['differences'], null, 'name');

    expect($operations['parent_fk']['automatic'])->toBeFalse()
        ->and($operations['parent_fk']['dependencies'])->toContain($operations['numeric_key']['id']);
});

it('uses an automatic referenced key instead of unrelated manual key changes', function (): void {
    $primary = ['kind' => 'p', 'definition' => 'PRIMARY KEY (id)', 'signature' => 'primary-id',
        'columns' => ['id'], 'backing_index' => 'parents_pkey'];
    $index = [...comparatorIndex('additional_unique'), 'unique' => true, 'keys' => [['column' => 'id']],
        'definition' => 'CREATE UNIQUE INDEX additional_unique ON public.parents USING btree (id)'];
    $foreign = ['kind' => 'f', 'definition' => 'FOREIGN KEY (parent_id) REFERENCES public.parents(id)',
        'signature' => 'fk-parent-id', 'columns' => ['parent_id'], 'backing_index' => null];
    $desired = comparatorSnapshot([
        'public.parents' => comparatorTable(['id' => comparatorColumn('integer', false)],
            ['additional_unique' => $index], ['parents_pkey' => $primary], 'parents'),
        'public.children' => comparatorTable(['parent_id' => comparatorColumn('integer')], constraints: ['parent_fk' => $foreign], name: 'children'),
    ]);
    $result = (new SchemaComparator)->compare($desired, comparatorSnapshot());
    $operations = array_column($result['differences'], null, 'name');

    expect($operations['parent_fk']['automatic'])->toBeTrue()
        ->and($operations['parent_fk']['dependencies'])->toContain($operations['parents_pkey']['id'])
        ->and($operations['parent_fk']['dependencies'])->not->toContain($operations['additional_unique']['id']);
});

it('orders new indexes after added columns and blocks unsafe prerequisites', function (): void {
    $desired = comparatorSnapshot(['public.sample_values' => comparatorTable(['email' => comparatorColumn()], ['email_idx' => comparatorIndex('email_idx')])]);
    $result = (new SchemaComparator)->compare($desired, comparatorSnapshot(['public.sample_values' => comparatorTable()]));
    $add = array_values(array_filter($result['differences'], fn (array $d): bool => $d['kind'] === 'add_column'))[0];
    $index = array_values(array_filter($result['differences'], fn (array $d): bool => $d['kind'] === 'create_index'))[0];
    expect($index['automatic'])->toBeTrue()->and($index['dependencies'])->toBe([$add['id']]);
    $actual = comparatorSnapshot(['public.sample_values' => comparatorTable(['email' => comparatorColumn('custom_type')])]);
    $result = (new SchemaComparator)->compare($desired, $actual);
    $index = array_values(array_filter($result['differences'], fn (array $d): bool => $d['kind'] === 'create_index'))[0];
    expect($index['automatic'])->toBeFalse();
});

it('keeps PostgreSQL indexes independent of unrelated manual column operations', function (string $change): void {
    $before = comparatorTable(['email' => comparatorColumn()]);
    $after = $before;

    if ($change === 'addition') {
        $after['columns']['required_name'] = comparatorColumn('text', false);
    } else {
        $before['columns']['required_name'] = comparatorColumn('integer');
        $after['columns']['required_name'] = comparatorColumn();
    }
    $after['indexes']['email_idx'] = comparatorIndex('email_idx');
    $result = (new SchemaComparator)->compare(comparatorSnapshot(['public.sample_values' => $after]), comparatorSnapshot(['public.sample_values' => $before]));
    $operations = array_column($result['differences'], null, 'name');

    expect($operations['required_name']['automatic'])->toBeFalse()
        ->and($operations['email_idx']['automatic'])->toBeTrue()
        ->and($operations['email_idx']['dependencies'])->toBe([]);
})->with(['addition', 'type change']);

it('uses verified PostgreSQL index key columns for exact multicolumn prerequisites', function (): void {
    $first = 'email, "quoted"';
    $second = '0';
    $index = comparatorIndex('lookup');
    $index['definition'] = 'CREATE INDEX lookup ON public.sample_values USING btree ("email, ""quoted""" DESC NULLS FIRST, "0")';
    $index['keys'] = [$first, $second];
    $desired = comparatorSnapshot(['public.sample_values' => comparatorTable([
        $first => comparatorColumn(), $second => comparatorColumn(),
        'email' => comparatorColumn('text', false),
    ], ['lookup' => $index])]);
    $result = (new SchemaComparator)->compare($desired, comparatorSnapshot(['public.sample_values' => comparatorTable()]));
    $operations = array_column($result['differences'], null, 'name');

    expect($operations['lookup']['automatic'])->toBeTrue()
        ->and($operations['lookup']['dependencies'])->toHaveCount(2)
        ->toContain($operations[$first]['id'], $operations[$second]['id'])
        ->not->toContain($operations['email']['id']);
});

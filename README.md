# Laravel Schema Align

Compare a consuming Laravel application's database schema with its complete current migration source. Review and explicitly approve selected safe SQL without resetting original tables or copying row data.

PHP **8.3+**, Laravel **12/13**, PostgreSQL **18+**, genuine MySQL **8.0.20+ (8.0 series) / 8.4**. MariaDB and Percona are unsupported. Runtime requires PHP CLI, the matching PDO extension, process execution, private temporary storage and access to the configured database server. **Docker, database clients and server binaries are not runtime requirements.** No web UI is included.

## Install

```sh
composer require korioinc/laravel-schema-align
php artisan schema-align:diff
```

The service provider is auto-discovered. Optional configuration publishing:

```sh
php artisan vendor:publish --tag=laravel-schema-align-config
```

The canonical file and namespace are `config/schema-align.php` and `schema-align`. Previously published `config/laravel-schema-align.php` must be renamed, references updated, and the consuming application's configuration cache rebuilt through its normal process. The Composer name and publish tag remain unchanged.

## How replay works

Every `diff` and `plan` uses the installed trusted application directly and replays **all selected migration files from empty**:

- PostgreSQL: a fresh schema for each configured original schema, inside the original database.
- MySQL: a fresh database on the original server, using the original charset/collation.
- Names use `align-MMDD-hhhhh`, for example `align-1021-a1b2c`. Date uses `app.timezone`, with UTC fallback. A full random run ID and durable ownership markers—not the name prefix—identify resources.
- Each run creates its own migration ledger. Already-applied create migrations edited afterward are replayed with their current bytes. Original migration history does not suppress replay.
- Schema dumps, incremental shadow ledgers, skipped failures, `migrate:fresh`, rollback and `db:wipe` are not used.

The original inspector uses a separate read-only session. Provisioning, migrations and cleanup use writable sessions with the configured account. **Original tables, rows and migration history are not changed by supported diff/plan replay, but temporary DDL does write to the connected infrastructure.** CPU, disk, locks, WAL/binlog and replication load are shared.

Managed replay checks DML write targets, including writable CTEs and `CREATE TABLE ... AS` query bodies, against the namespaces owned by the run. MySQL joined or multi-table updates/deletes, CTE `SEARCH`/`CYCLE` clauses and PostgreSQL explicit `setval`/`nextval` calls are unsupported and stop replay before execution. PostgreSQL `pg_*` relation targets resolved through the search path require an explicit owned schema qualifier in both DML and DDL because system catalogs precede that path. PostgreSQL serial/identity defaults still work in the temporary tables.

Each PostgreSQL replay connection must declare an explicit `search_path` (or legacy `schema`) containing only configured comparison schemas. Replay maps that connection's path to its owned temporary schemas, preserving the order and subset. Server-default paths, dynamic `$user` entries and paths outside the comparison scope are rejected before SQL executes on that connection.

Named and dynamic replay connections retain their own Laravel connector session settings, including timezone, charset/collation, isolation level, PostgreSQL synchronous commit and MySQL SQL modes. Missing settings use connector/server defaults instead of inheriting the selected connection's values. Auxiliary PDO `options` and PostgreSQL `server_options` must match the selected connection; unsupported differences stop replay before that auxiliary PDO is opened. Target binding and owned namespace checks still apply.

The worker runs on the consuming application's server, using the current PHP runtime, the existing application and its installed Composer autoloader. Application and vendor directories are not copied, and Composer metadata is not rewritten. Composer-installed path packages work through their existing symlinked or copied (`options.symlink=false`) installations; edits to the original source of a copied installation take effect after reinstalling it. Custom Composer vendor directories are resolved from `COMPOSER_VENDOR_DIR` or `config.vendor-dir`. The consuming application's resolved connection remains authoritative; package/workbench databases are never a fallback. No Composer installation, image build/pull or Docker daemon call occurs.

Composer controls the active autoload sources, including root `autoload-dev` files and installed path packages. A `--no-dev` installation uses its existing production autoloader. Replay does not interpret repository URLs or load dependencies merely because they remain in `composer.lock`.

The coordinator creates a private temporary directory only for worker cache, environment and storage files. The worker receives effective application configuration, including resolved DB credentials and cached values, through private stdin rather than process arguments or plan metadata. It does not reload the application's `.env`; original cache and storage remain untouched by the replay infrastructure. Configuration must retain its arrays, scalar values and nulls through JSON transport; unsupported values stop replay. Application and package paths stay unchanged; configured paths beneath the original storage directory map to private worker storage. Worker DB/cache/session/queue/log overrides remain active, and unused schema dumps are not imported. Errors redact known credential values. Temporary runtime files are removed after worker termination on both success and failure.

Windows replay requires PowerShell and a private NTFS temporary directory. Only the current account, SYSTEM and Administrators may own or have allowed access to replay storage. Trusted grants must propagate to files and subdirectories. Replay checks these ACLs when creating private worker storage and again before writing the worker's configuration cache; shared or unverifiable permissions stop replay. Configure PHP's `sys_temp_dir` to use suitable private storage. The package does not change shared directory ACLs.

Replay verifies the selected migration paths, complete file set, file hashes and `composer.lock` before and after execution. The source `revision` fingerprints these migration inputs, lock bytes and PHP runtime; it is not a fingerprint of the entire application or vendor tree. Keep the installed release stable while comparison runs: changes to helpers or providers outside the selected migrations are not detected by this check. No application-source secret scan or immutable source snapshot is created.

**This is trusted-code execution, not a sandbox.** Application bootstrap, providers and migrations run in the same OS/Pod trust boundary. They can read mounted secrets or deliberately create their own connections. Connection redirection guards mistakes, not hostile PHP. Do not run untrusted migrations. Data-dependent migrations and external API, queue or filesystem effects are not a supported schema baseline.

## Configure original scope

```php
// consuming application's config/schema-align.php
return [
    'connection' => null, // database.default, or an existing named connection
    'driver' => null, // detect pgsql/mysql; an explicit value must match
    'target_instance' => '', // derived from resolved binding/environment
    'plan_directory' => null, // storage/app/schema-align
    'engines' => [
        'pgsql' => [
            'schemas' => ['public'], // original scope, e.g. ['accounting', 'reporting']
            'migration_table' => 'migrations',
            'lock_timeout_ms' => 5000,
            'statement_timeout_ms' => 0, // API default: unlimited apply SQL execution
        ],
        'mysql' => [
            'migration_table' => 'migrations',
            'lock_timeout_ms' => 5000,
        ],
    ],
    'replay' => ['migration_paths' => [], 'timeout' => 180],
];
```

PostgreSQL scope must exist and be nonempty. Explicit schema lists replace defaults; empty lists and conflicting nondefault legacy scopes are rejected rather than falling back to `public`. Multiple schemas remain distinct. Logical scope is sorted; each connection's search path determines replay creation placement, and the default connection determines the ledger location. A migration ledger found in more than one selected schema is ambiguous and refused. Replay search paths contain temporary schemas only—never `public`, the original schema or `$user` as fallback. MySQL uses the selected connection's database, represented internally as `@database`; it has no `public` default.

`migration_table` is the logical Laravel table name. The connection's `prefix` is applied once when catalogs and executors resolve the physical ledger name: `prefix=p_` and `migration_table=migrations` use `p_migrations`. That physical table stays outside structural comparison and is recorded in the saved plan's scope; replay passes the logical name to Laravel's migrator. Regenerate pending plans created before this fix when using a nonempty connection prefix.

Laravel connection URL resolution, socket and TLS settings are retained. Read/write routing is unsupported. Missing capabilities, incomplete catalog visibility and unsupported original objects fail before namespace creation. Enabled PostgreSQL database event triggers are refused before any provisioning DDL.

Package-owned connections disable PDO persistence so replay and inspection session settings stay isolated from the consuming application's connections.

### Schema-qualified migrations

Unqualified Laravel schema operations follow the replay connection. Hardcoded original references such as `public.orders` or `original_db.orders` are not rewritten. For PostgreSQL migrations that intentionally address several schemas, use the worker's runtime mapping:

```php
$schema = config('schema-align.replay.schema_map.accounting', 'accounting');
Schema::create($schema.'.orders', function (Blueprint $table): void {
    $table->id();
    $table->string('reference')->index();
});
```

The replay guard decodes doubled quotes in SQL identifiers and follows each engine's comment syntax. Session-scope checks include quoted setting names and quoted `set_config` calls. DDL qualifiers must refer to temporary namespaces owned by the current run, including references outside the selected comparison scope. Column comments support both `table.column` and `schema.table.column`; ambiguous qualifiers in other raw DDL expressions are refused. Moving objects between schemas, `EXPLAIN`, procedural blocks, stored-program creation/calls and prepared SQL are unsupported. Escape-dependent literals, Unicode-escaped identifiers and executable MySQL comments are refused rather than guessed. This is mistake prevention on managed connections, not isolation from trusted PHP.

Replay refuses privilege and account changes, including `GRANT`, `REVOKE` and `ALTER DEFAULT PRIVILEGES`. MySQL global and persistent assignments are refused in both keyword and `@@` forms, including mixed assignment lists; reads of global settings remain allowed. Numeric PostgreSQL schema names are preserved through worker mapping, normalization and owned cleanup.

The SQL guard accepts only recognized command families. DDL is limited to table, index and sequence operations, plus table/column/index/constraint comments. Schema/database comments, publications, subscriptions, bulk tablespace operations and other unverified object kinds are refused before execution. `SELECT INTO` is unsupported, including after a CTE; ordinary reads and CTE data changes retain their existing checks. Normal transaction control is supported; prepared transactions and replication-control commands are refused.

The mapping exists only in the replay worker. Worker-only builders generate default index/constraint names from the logical original table name, while executing SQL in the temporary schema. Explicit names remain unchanged. Laravel's `resolving` and `afterResolving` callbacks for Blueprint instances run during replay. Custom connection/Blueprint resolvers that cannot preserve this contract are refused. Container bindings, contextual bindings and extenders for `Illuminate\Database\Schema\Blueprint` or the replay Blueprint are refused, as are matching `beforeResolving` callbacks and global `beforeResolving` callbacks. These checks also run after construction to reject unsupported hooks registered while the Blueprint is being resolved. Named Laravel connections are redirected only when their resolved engine and target binding match the selected connection. Using another target or direct/read/write routing stops replay before a plan is produced. Raw PDO, changing search paths/roles, reconnect/failover and global DDL are unsupported.

### Account capabilities: one existing application connection

A dedicated read-only login is not required. PostgreSQL needs database `CREATE` and rights to replay and clean its owned schemas. No new role, `CREATEROLE` or automatic privilege grant is required. PostgreSQL superusers can compare and apply approved signed plans. Superuser access is advisory, not a reason to refuse apply; use only trusted migrations and review SQL carefully. Connection warnings appear in detailed comparison output and signed plans, not the compact diff.

MySQL catalog inspection needs schema-wide `SELECT`, `SHOW VIEW`, `TRIGGER`, `EVENT`, `EXECUTE` plus global `SHOW_ROUTINE` or equivalent metadata visibility. Administrator accounts satisfy these checks through their effective global privileges. Active-role grant expansion is currently unsupported.

MySQL snapshot hashes include the effective session values of `character_set_client`, `character_set_connection`, `character_set_results`, `collation_connection` and `sql_generate_invisible_primary_key`. The last setting is read on MySQL 8.0.30+ and 8.4; earlier supported releases record it as disabled. Changing these values after planning invalidates approval before DDL runs. Regenerate MySQL plans saved before these session settings were included in snapshot hashes.

MySQL replay ownership markers have an explicit primary key and work with `sql_generate_invisible_primary_key` and `sql_require_primary_key` enabled. Migrations still need to satisfy the configured policies. Existing markers with only a `payload` column remain inspectable and cleanable; markers with unexpected extra columns remain untrusted.

Use the consuming application's existing connection, normally resolved from `.env` through Laravel's database configuration. **No second account, cleanup connection or additional credentials configuration is required.** MySQL may use database-scoped change privileges together with global `SELECT`, `SHOW VIEW`, `PROCESS`, `SHOW DATABASES`, `SHOW_ROUTINE` and `USAGE`. These inspection grants do not block approved apply. Cleanup still verifies complete visibility before creating temporary databases; the package never grants missing privileges automatically.

MySQL `root`, global write/DDL privileges, `ALL PRIVILEGES ON *.*`, administrative/dynamic privileges and `WITH GRANT OPTION` do not block approved apply. PostgreSQL superusers follow the same policy: account privilege level produces an advisory warning, not a refusal. Plans, workers and cleanup retain the configured account and target binding. Unsupported proxy/role grant evidence, active roles and partial revokes still fail closed when complete metadata visibility cannot be established. Elevated privileges expose other databases and can bypass server protections; use only trusted migrations and review the displayed SQL. The package never grants or substitutes credentials.

### Previously published Docker settings

Remove explicit Docker settings before namespace replay. Old `null` image/revision values, `trusted_image=false`, empty engine arguments/extensions and `build_timeout=600` are accepted as obsolete defaults. Nondefault values fail before DDL with the offending key; an explicit release image is never silently replaced with installed source. New published configuration omits those keys. Existing flat PostgreSQL scope/ledger/timeout settings retain conflict detection and compatibility.

## Compare → select → review → apply

```sh
php artisan schema-align:diff # Human summary grouped by table.
php artisan schema-align:diff --details # Definitions, operation IDs and SQL.
php artisan schema-align:diff --json # Compact machine-readable result.
php artisan schema-align:diff --json --details # Full snapshots and diagnostics.
php artisan schema-align:plan  # Defaults to all-safe and shows the saved SQL plan.
php artisan schema-align:apply # Reviews that plan, then requires exactly yes.

# Select only particular changes if needed:
php artisan schema-align:plan --select=OPERATION_ID --select=OTHER_ID
# Explicit automation approval (never inferred from --no-interaction):
php artisan schema-align:apply --auto-approve --no-interaction --json
# Inspect latest execution status without connecting to the database:
php artisan schema-align:apply --status --json
# Legacy explicit plan/signature approval remains supported:
php artisan schema-align:apply PLAN_ID --approve=EXACT_PLAN_SIGNATURE --json
```

`diff`, `plan` and `apply` default to human-readable output; add `--json` for machine-readable results. Diff groups changes by table, counts automatic versus manual operations, and reports clean/incomplete states with next steps. JSON apply never prompts. Pending work requires explicit `--auto-approve` or legacy signature approval; a live-verified `already_applied` result needs neither. Default diff output omits `historical_missing_files` and account-only connection warnings, while retaining schema warnings, errors and unsupported-object diagnostics. Human `--details` adds operation IDs, before/after definitions, SQL, migration history and connection warnings. `--json --details` preserves the complete comparison, snapshots and replay evidence. Here replay means local PHP execution in temporary namespaces, not Docker. This changes presentation only. The PHP `SchemaAlign::diff()` API returns the complete comparison. Exit codes remain **0 clean, 2 drift, 1 failed/incomplete**; other commands return 0 on success, 1 on failure. Cleanup failure is not clean or drift success and cannot be pinned into a plan.

`diff` reports current schema differences. `plan` performs the same comparison, displays SQL and saves a signed plan; omitting CLI `--select` defaults to `all-safe`. Manual/unsupported operations are excluded, and no eligible operation still produces an error rather than an empty executable plan. Explicit IDs remain supported. PHP `plan()`/`pin()` callers still supply their selection. Neither command applies SQL to original objects.

`apply` without arguments loads the latest signed plan and checks the live database before showing SQL. If that exact plan is already reflected in the database, it reports `Already applied` without replay, SQL preview, confirmation or original-object changes. It then retries safe cleanup of inactive replay resources owned by the same target and scope. It does not trust the saved execution status alone. Live-verified completion also reconciles an interrupted `executing` or `partial` journal to the completed outcome, without repeating original SQL. This original-SQL no-op also works with `--no-interaction` and `--json` without another approval, but may delete verified inactive temporary resources; JSON returns `status: already_applied` when cleanup succeeds. A supplied incorrect legacy signature is still rejected. Saved plans remain fixed: run `plan` after editing migrations to compare the new source.

For pending work, `apply` displays only SQL still to execute and requires the exact response `yes`. If no plan has been saved, interactive or explicitly approved apply first generates an all-safe plan for review. Cancellation or unapproved noninteractive execution never applies changes; unapproved automation without a saved plan creates neither replay resources nor a plan. A stale, tampered, missing or differently bound saved plan is never silently replaced. Run `plan` again to refresh it. The displayed ID/signature stays pinned even if another process publishes a newer plan during confirmation. The executor rechecks live state under its apply lock after approval. Partial MySQL checkpoints and earlier unsuccessful attempts still require explicit `--recover`; recovery previews only remaining operations.

Plans pin selected SQL, source revision/migration digest, resolved connection/environment/scope, original database identity and structural before/after hashes. A durable signed pointer identifies the latest published plan; filesystem timestamps do not select it. Applying a saved plan does not replay migrations or regenerate SQL. HMAC validation, stale/tamper detection and existing format-1 plans remain supported. Older plans without a latest pointer remain accessible by explicit ID. Changing account/target requires a new plan. MySQL administrator and PostgreSQL superuser apply are supported without an extra flag or second account. Approval, HMAC, target/account/environment/scope, identity, baseline, locking and engine-specific postcondition checks remain mandatory. Changing the effective role after planning still invalidates its identity binding. PostgreSQL retains transactional apply; MySQL retains per-statement commits and verified checkpoint recovery. Existing valid plans remain usable; old saved account-refusal warnings are identified as obsolete in the human preview without changing signed artifacts.

## Long-running apply

`schema-align:apply` removes PHP's execution timer and defaults to **no SQL execution deadline**, even when an older published PostgreSQL configuration still contains `statement_timeout_ms=30000`. A statement taking an hour or longer is not cancelled by that package deadline. PostgreSQL uses transaction-local `statement_timeout=0` and disables PostgreSQL 17+ `transaction_timeout` during apply; those session settings revert after commit or rollback. MySQL DDL already has no statement execution timeout. Apply runs in-process, not under replay's subprocess timeout.

For an intentional PostgreSQL per-statement limit, use `php artisan schema-align:apply --timeout=7200` (seconds). `--timeout=0` means unlimited. This is not a whole-command deadline. MySQL accepts only `--timeout=0` because its SELECT-only `max_execution_time` cannot limit DDL. PHP `SchemaAlign::apply()` callers retain configured milliseconds when its optional fourth argument is omitted; passing `0` explicitly disables the SQL limit.

Lock waits remain bounded separately by `lock_timeout_ms`, including catalog/ledger reads before approval and execution; this can still refuse waiting for a conflicting lock. Incoming session timeout settings are restored after review or apply. Replay retains its own timeout, and post-apply cleanup retains its separate 30-second child deadline. Cleanup timeout does not cancel or roll back completed original SQL. External supervisors, terminal disconnection, database/proxy/driver deadlines, network failure, failover, `SIGTERM` and `SIGKILL` can still interrupt execution. Run long operations in a persistent terminal/process environment; this is not an uninterruptibility guarantee. If PHP's timer cannot be removed, the command refuses before applying and explains `php -d max_execution_time=0 artisan schema-align:apply`.

## Cleanup and interruption

Normal cleanup runs after worker termination and verifies durable markers, original server identity, exact namespace mapping, independent coordinator/worker leases and registered DB session termination. A parent process disappearing does not authorize deleting a surviving worker's resources. Prefixes, dates and TTL alone never authorize deletion.

```sh
php artisan schema-align:cleanup EXACT_32_CHARACTER_RUN_ID --json
```

After all original operations succeed, apply verifies the completed schema and removes inactive temporary PostgreSQL schemas or MySQL databases with matching durable ownership markers, connection binding, original identity and schema scope. This includes abandoned earlier inspection runs for that exact target and scope. Normal diff/plan replay still cleans its own run immediately; apply is an additional cleanup opportunity, not a reason to retain replay databases. Original objects, unmarked resources, other targets/scopes and active runs are never swept by prefix or age.

Original application and cleanup have separate outcomes. If cleanup is blocked, `apply` returns `applied_cleanup_blocked` and a nonzero exit while preserving the committed application result. It reports retained run IDs/reasons. Retrying `apply` verifies completion and retries cleanup without rerunning original SQL. Partial/failed application, cancellation, `reviewPlan()` and offline `--status` never start this cleanup. Legacy plans without signed namespace evidence do not authorize automatic discovery; use exact-run cleanup after ownership review.

Only that exact owned inactive run can be removed. PostgreSQL uses dependency-aware `RESTRICT` removal, not unconditional `CASCADE`. External dependencies, active triggers, unknown markers or uncertain sessions leave resources intact and report an error. MySQL retains foreign-key checks and refuses external dependencies. Cleanup can be repeated; confirmed absent resources produce `already_absent` without DDL.

MySQL `CREATE DATABASE` implicitly commits. A crash before its marker is persisted can leave an unmarked database. It is never automatically adopted or deleted. Record the exact failure diagnostics for manual reconciliation. Every subsequent diff uses a new namespace, not the leftover ledger.

Replay process timeout does not prove DB statement cancellation. PostgreSQL cleanup bounds individual statements and lock waits. MySQL lock waits are bounded, but server DDL may outlive a lost client; a hard total DROP duration/cancellation guarantee is not established. Errors retain the original replay failure plus cleanup run/resource diagnostics.

## Comparison and automatic SQL scope

PostgreSQL compares column types/modifiers, nullability, defaults, identity/generated expressions, collations, PK/UNIQUE/FK/CHECK and supported named NOT NULL constraints, standalone index semantics and unambiguous name-only differences. Temporary namespaces normalize structurally; string literals and explicit object names are not blindly replaced.

PostgreSQL relation-name collision checks include actual sequence names, including renamed serial/identity sequences and migration-ledger sequences, plus the excluded migration ledger's table and all its indexes. These occupied names participate in fingerprints, but sequence counter values do not. Regenerate PostgreSQL plans saved before this metadata was included; their old baseline fingerprints are rejected before executing DDL.

MySQL column additions whose names collide under the server's identifier rules require manual review. Table names are case-sensitive when `lower_case_table_names=0`; case-colliding table additions require manual review for settings `1` and `2`. No table rename is inferred. Independent safe changes can still be selected with `all-safe`.

MySQL catalogs record server-calculated identifier keys for tables, columns and indexes. Comparison uses those keys rather than PHP Unicode folding or accent-insensitive collation equality, preserving distinctions such as `ß` and `ẞ`. The keys participate in snapshot fingerprints and apply checkpoints. Regenerate existing MySQL comparisons and pending plans created before these keys were captured. Legacy snapshots without keys can compare identical names, but uncertain Unicode additions and renames require a fresh catalog inspection.

MySQL has its own catalog/compiler and compares types/unsigned/defaults, auto-increment/generated attributes, charset/collation, PK/FK/CHECK, indexes and InnoDB table properties. Its logical `@database` prevents temporary DB names from becoming drift. Row counts, next sequence/auto-increment values and physical column order are not synchronized.

Explicit InnoDB `DYNAMIC`, `COMPACT` and `REDUNDANT` row formats are supported. Index additions that could replace an existing nonunique supporting index require manual review: MySQL does not expose whether a foreign key originally created that index, and the index can remain after the foreign key is removed. Numeric SQL identifiers retain their names through snapshot storage and generated SQL.

Regenerate pending plans created before this normalization change if they contain numeric-only identifier maps. Their checkpoint fingerprints can differ after identifier maps are sorted independently of physical column order.

Automatic operations remain deliberately narrow: supported plain new tables, nullable plain columns, ordinary nonunique indexes, eligible new-table constraints and unambiguous semantic-preserving names. Destructive operations, data backfills, existing uniqueness validation, ambiguous renames, type/default/generated changes and unsupported transformations require manual decisions. No table/column rename is inferred from missing+extra objects.

Incomplete catalogs and detected unsupported features block executable plans: partitions/inheritance, views, routines, triggers/events, RLS, custom types/functions/collations, extension-owned objects, invalid indexes, external dependencies and unsupported storage properties. Existing engine-specific limitations remain in force. Installing an extension does not make its objects supported.

## Apply safety and recovery

Plan storage is private (`0700` directories, `0600` files/key on Unix). Windows validates NTFS ACLs and refuses unrelated inherited/shared access. Keep issuing plans and keys on trusted durable local storage. The key holder can issue and approve plans; this is not multi-user approval.

PostgreSQL serializes package applies with advisory/table locks, rechecks the baseline after locking, applies transactional DDL and verifies postconditions before commit. Rollback and lost-acknowledgement recovery use observed schema state. MySQL DDL implicitly commits: each operation is one signed statement with a before/after checkpoint, durable intent and post-statement introspection under a database-scoped `GET_LOCK()`. A committed prefix is never blindly rerun.

```sh
php artisan schema-align:apply --recover # Reviews the saved plan and asks for yes.
# Or explicitly approve automation:
php artisan schema-align:apply --recover --auto-approve --no-interaction --json
```

Recovery requires a uniquely verified original baseline or MySQL checkpoint. Matching final structure is reconciliation, not proof of exactly-once execution. Divergence requires a new comparison/plan. No universal rollback or data restoration is promised. Package locks do not fence unrelated external DDL, and exclusive locks can block traffic.

## PHP API

```php
$align = app(\Korioinc\SchemaAlign\SchemaAlign::class);
$comparison = $align->diff();
$plan = $align->pin($comparison, ['OPERATION_ID']);
$result = $align->apply($plan['id'], $plan['signature']); // separate approval action
$cleanup = $align->cleanup('EXACT_32_CHARACTER_RUN_ID');
```

## Package development verification

### Local development in a consuming application

Install this checkout into a separate Laravel application to develop and test the package against that application's migrations and local PostgreSQL or MySQL connection. The example below assumes the application and package checkout are sibling directories; adjust `url` to your checkout path.

Add this entry to the consuming application's `composer.json` repositories, preserving any existing entries:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../laravel-schema-align",
            "options": {
                "symlink": true,
                "versions": {
                    "korioinc/laravel-schema-align": "dev-main"
                }
            }
        }
    ]
}
```

Run these commands from the consuming application's directory:

```sh
composer require korioinc/laravel-schema-align:dev-main
php artisan schema-align:diff
```

The explicit local version lets Composer resolve the checkout regardless of its Git branch name. Symlinking makes package source edits available to the consuming application. After changing package dependencies or Composer metadata, run `composer update korioinc/laravel-schema-align` in that application.

### Automated package checks

Run these commands from the package checkout. Orchestra Testbench supplies the Laravel environment for automated tests; installed-application and path-package fixtures exercise package installation and replay in separate temporary applications.

```sh
composer test
composer lint:check
composer analyse
composer test:unit
```

These are package checkout commands, not installed application Artisan aliases. Real DB checks use explicitly disposable loopback infrastructure only. PostgreSQL uses `SCHEMA_ALIGN_TEST_DSN`, `SCHEMA_ALIGN_TEST_USER=schema_align_test` and `SCHEMA_ALIGN_TEST_PASSWORD`; installed/path-package tests additionally use `SCHEMA_ALIGN_AUTO_REPLAY_TESTS=1` / `SCHEMA_ALIGN_PATH_PACKAGE_TESTS=1`. PostgreSQL superuser apply and safety scenarios require `SCHEMA_ALIGN_SUPERUSER_TESTS=1`, `SCHEMA_ALIGN_TEST_ADMIN_USER` and `SCHEMA_ALIGN_TEST_ADMIN_PASSWORD` for the disposable target. MySQL uses corresponding `SCHEMA_ALIGN_MYSQL_*` variables; administrator apply and namespace safety probes require `SCHEMA_ALIGN_NAMESPACE_MYSQL_ADMIN_TESTS=1` and explicit `SCHEMA_ALIGN_MYSQL_ADMIN_USER` / `SCHEMA_ALIGN_MYSQL_ADMIN_PASSWORD`. No test-only admin credential fallback exists in production.

Missing integration credentials produce skips, not proof. The `Database integration - P8.5 - L13 - stable` CI job provisions disposable PostgreSQL 18.2 and MySQL 8.4.11 services, enables all database scenarios and fails on skipped, incomplete or empty tests. The existing PHP/Laravel/Windows dependency matrix remains separate. Repository branch protection must require this job if merges must wait for it.

Local verification does not establish actual Kubernetes permissions, managed-service limits or the full PHP 8.3/8.4/8.5, Laravel 12/13 and Windows database runtime matrix. Test infrastructure may use local containers, independently of the Docker-free package runtime. See [`tests/Fixtures/replay/README.md`](tests/Fixtures/replay/README.md).

### Source organization

The source follows responsibility-based namespaces. `SchemaAlign` coordinates the workflow; `SchemaAlignServiceProvider` registers the package with Laravel.

```text
src/
├── SchemaAlign.php
├── SchemaAlignServiceProvider.php
├── Console/
│   ├── Commands/                  # Artisan commands
│   └── PlanPreview.php            # Shared console presentation
├── Database/
│   ├── EngineConfiguration.php
│   └── TargetConnection.php
├── Schema/
│   ├── Snapshot.php
│   ├── NamespaceNormalizer.php
│   ├── Catalog/                   # PostgreSQL and MySQL inspection
│   └── Comparison/                # Engine-specific differences and SQL
├── Planning/
│   ├── Operation.php              # Shared operation contract and projection
│   └── PlanStore.php              # Signed plans and execution journals
├── Execution/
│   ├── ApplyExecutor.php          # PostgreSQL transactional apply
│   └── MySqlApplyExecutor.php     # MySQL checkpointed apply
└── Replay/
    ├── InstalledAppSource.php
    ├── ReplayWorkspace.php
    ├── ReleaseReplayer.php
    ├── MySqlReplayer.php
    ├── ReplayNamespace.php        # Owned namespaces, leases and cleanup
    ├── ReplayCompletion.php       # Worker completion contract
    ├── ReplaySqlGuard.php
    ├── Connections/               # Registered Laravel replay connections
    └── Schema/                    # Replay builders and Blueprint naming
```

Keep engine differences within the responsibility they implement. Replay connection and schema overrides belong to `Replay`, because they enforce worker-specific behavior. The standalone worker entry scripts remain in `resources/`.

Class namespaces match their directories under `Korioinc\SchemaAlign`. Direct imports of moved classes must use their new namespaces. For example, `Korioinc\SchemaAlign\PlanStore` becomes `Korioinc\SchemaAlign\Planning\PlanStore`, including the return type exposed by `SchemaAlign::store()`. The `SchemaAlign` and service provider namespaces, Artisan command names, signed plan format and worker payload format remain unchanged.

### Internal change contracts

[`Operation`](src/Planning/Operation.php) defines the shared comparator output and owns its structural projection. Each new automatic operation must prove comparison → signed projection/checkpoints → real-engine apply. Manual differences have no SQL; unknown automatic kinds cannot become executable plans. PostgreSQL transactional and MySQL single-statement checkpoint rules remain engine-owned. Signed format-1 plans and existing operation IDs are unchanged.

[`ReplayCompletion`](src/Replay/ReplayCompletion.php) defines the worker completion version, naming strategy, migration digest and complete sorted migration names. The coordinator checks the version and naming strategy, independently derives the expected digest from installed migration revision/lock/manifest bytes, and rejects mismatched or incomplete evidence before reading the replay catalog. A protocol version change must update both ends; there is no legacy fallback. Completion does not authorize namespace deletion: [`ReplayNamespace`](src/Replay/ReplayNamespace.php) still owns marker, lease, session and identity checks.

## License

[MIT](LICENSE.md).

# Release Notes

## [Unreleased](https://github.com/korioinc/laravel-schema-align/compare/1.0.4...main)

## [1.0.4](https://github.com/korioinc/laravel-schema-align/releases/tag/1.0.4) - 2026-10-25

### Highlights

- Migration files remain the desired schema. Supported PostgreSQL type changes and column removals now produce executable signed plans instead of requiring manual SQL.
- New projection rules use signed format 3, which older package versions reject. Existing format-1 and format-2 plans remain readable.

### New Features

- Plan explicit timestamp precision increases and `character(n)` to `character varying(m)` expansions with `m >= n`, including verified dependent btree index and primary/unique constraint effects.
- Plan removal of columns with integer literal defaults and validated column-owned PostgreSQL NOT NULL constraints. Apply still requires normal approval and `--allow-drop-columns`.

### Bug Fixes

- Handle the supported type and removal differences that previously left PostgreSQL plans without executable operations. Required missing-column mappings and unsupported transformations remain manual.
- Reject padded character values before conversion. Preserve unique constraints, exact postcondition checks and transactional rollback.

## [1.0.3](https://github.com/korioinc/laravel-schema-align/releases/tag/1.0.3) - 2026-10-25

### Changed

- Default CLI planning selects all supported changes, including eligible PostgreSQL column removals. A pending plan containing removals executes no SQL without `--allow-drop-columns`, even with `--auto-approve` or signature approval.
- Remove the `all-safe` selection mode. Use explicit operation IDs when selecting only part of a comparison; removal permission is controlled at apply.
- Removal plans use signed format 2, which older package versions refuse. Ordinary format-1 plans remain readable. Regenerate older plans involving tables with extended statistics because their fingerprints now include a removal blocker.

### Added

- Explicit PostgreSQL column removal through `schema-align:apply --allow-drop-columns` and the PHP API's `allowDropColumns: true` argument, in addition to normal approval.
- Removal SQL, data-loss warnings and deferred changes in plan/apply output, including JSON refusal details and completed-plan summaries.
- A red manual-review footer in human-readable diff output for tables absent from current migrations. Table deletion remains manual, and JSON output is unchanged.
- Dependency checks, `DROP COLUMN ... RESTRICT`, transaction rollback and regressions for populated columns, fixed selections, stale plans and outside-schema dependencies. MySQL column removal remains manual.

### Upgrade

- Update `korioinc/laravel-schema-align` to `1.0.3` in the consuming application.
- Generate a fresh plan to include removals; the apply option never expands an existing signed plan.
- To remove approved columns without a confirmation prompt, use `php artisan schema-align:apply --allow-drop-columns --auto-approve`.

## [1.0.2](https://github.com/korioinc/laravel-schema-align/releases/tag/1.0.2) - 2026-10-25

### Fixed

- Accept legacy PostgreSQL serial sequences stored as `bigint` when their ownership, default reference and bounds match the owning `smallint` or `integer` column. This includes Laravel's `migrations_id_seq` and allows schema comparison without changing the original sequence.
- Retain rejection of missing ownership/default references, custom or oversized bounds and mismatched identity sequence types.

## [1.0.1](https://github.com/korioinc/laravel-schema-align/releases/tag/1.0.1) - 2026-10-24

Replacement for the withdrawn initial release. Runtime behavior and dependency requirements are unchanged.

### Changed

- Publish the package with the unprefixed `1.0.1` tag.
- Require stable `MAJOR.MINOR.PATCH` tags without a `v` prefix in release automation and contributor instructions.

### Features

- Compare an application's database schema with its current migrations by replaying them in owned temporary namespaces.
- Review and save signed SQL plans with explicit approval and target binding before applying supported changes.
- Apply PostgreSQL changes transactionally and track MySQL DDL checkpoints for explicit recovery after interruption.
- Clean up owned temporary namespaces and verify private worker storage, including Windows NTFS permissions.
- Install through Composer with Laravel service provider discovery and `schema-align:diff`, `schema-align:plan`, `schema-align:apply`, and `schema-align:cleanup` commands.

### Requirements

- PHP `^8.4` and Laravel `^13.0`.
- PostgreSQL 18+ or genuine MySQL 8.0.20+ within the 8.0 series, or MySQL 8.4. MariaDB and Percona are unsupported.
- PHP CLI, the matching PDO extension, process execution, and private temporary storage. Docker and database client binaries are not runtime requirements.

# Release Notes

## [Unreleased](https://github.com/korioinc/laravel-schema-align/compare/1.0.1...main)

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

# Contribution Guide

Thank you for considering contributing to Laravel Schema Align! Please review the following guidelines before submitting a pull request.

For significant changes, please open an issue first so we can discuss the approach.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

## Guidelines

- Ensure the coding style passes by running `composer lint`.
- Send a coherent commit history, making sure each commit in your pull request is meaningful.
- You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- Please remember that we follow [SemVer](http://semver.org/).

## Setup

Clone your fork, then install the dev dependencies:

```bash
composer install
```

For manual development, install this checkout into a separate Laravel application using a Composer path repository with symlinking enabled. Follow the [local development workflow](../README.md#local-development-in-a-consuming-application). Run application Artisan commands in that application and package checks in this checkout.

## Lint

Lint your code:

```bash
composer lint
```

## Tests

Run all tests:

```bash
composer test
```

## Releases

Use stable `MAJOR.MINOR.PATCH` tags without a `v` prefix, such as `1.0.1`.
This matches `korioinc/laravel-expo-integrity`. The Release workflow rejects prefixed tags.

1. Verify that CI passes for the intended commit on the default branch.
2. Create and push the approved release tag for that commit.
3. Run **Actions → Release → Run workflow** on the default branch with the existing tag.
4. Confirm that the workflow checks, GitHub release, and Packagist version all match the tagged commit.

The workflow does not create tags. It verifies the tag's commit and refuses publication if the tag changes during validation.
Packagist imports versions from pushed tags, so CI must pass before the tag is pushed.
The changelog updater skips an existing version heading to keep retries idempotent.

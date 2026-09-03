# Development and Testing

## Supported versions

The declared support range is maintained in [version.php](../version.php). The current release is `0.4.0-beta`; check that file rather than this document before changing compatibility claims.

## Source layout

* `classes/`: domain logic, external APIs, forms, events, notifications, and privacy provider.
* `amd/src/`: ES module source; generated files belong in `amd/build/` when Moodle's build process is run.
* `templates/`: Mustache templates.
* `db/`: capabilities, services, database schema, upgrades, and caches.
* `backup/moodle2/`: Moodle backup and restore support.
* `tests/`: PHPUnit tests and test generators.

## Local preflight

Run a local static preflight before pushing. At minimum, run PHP syntax checks, `git diff --check`, and Moodle CodeSniffer. This is a fast gate; it does not replace integration tests.

## CI

GitHub Actions workflows are stored in [.github/workflows](../.github/workflows):

* `moodle-preflight.yml`: fast static validation for pull requests and manual runs.
* `moodle-ci.yml`: matrix validation across supported Moodle releases and MariaDB/PostgreSQL, including static checks, AMD build, PHPUnit, and Behat.

The matrix is intentionally broader than the development server. A change that works locally can still fail because of database portability, Moodle API changes, generated AMD output, or browser-level behaviour.

## Test selection

| Change type | Minimum validation |
| --- | --- |
| PHP logic or permissions | PHPUnit test that fails before the fix, plus preflight. |
| Backup/restore | PHPUnit coverage for user-data and no-user-data behaviour where affected. |
| JavaScript / UI | AMD build, relevant Behat scenario where available, and manual browser validation. |
| Database/query | MariaDB and PostgreSQL CI jobs. |
| Capability changes | PHPUnit/API test plus manual role validation in a Moodle test site. |

## Coding guidance

Use Moodle APIs for contexts, capabilities, parameters, database access, strings, files, and output. Treat external function declarations and their runtime authorization as separate safeguards. Keep PostgreSQL portability in mind: do not compare text/blob fields in condition arrays where Moodle's DML layer forbids it.


# Contributing

Contributions must preserve Moodle compatibility, data privacy, and the behaviour documented in this repository.

## Before opening an issue

Use the relevant issue form. Include the Moodle version, plugin release, PHP version, database engine, and steps that reproduce the result. Remove passwords, session keys, student data, and production logs containing personal information.

Security vulnerabilities must not be reported through public issues. Follow [SECURITY.md](SECURITY.md).

## Proposing a change

Describe the user problem before proposing an implementation. Changes that alter backup and restore, permissions, groups, privacy, or activity completion must describe their effect on existing Moodle sites.

For a bug fix, add a regression test when the affected code can be covered by PHPUnit or Behat. For a user-interface change, state the manual Moodle flow used to validate it.

## Preparing a pull request

1. Start from the current default branch.
2. Keep the change focused and avoid unrelated formatting or generated-file changes.
3. Use Moodle APIs and coding conventions.
4. Update documentation when public behaviour, compatibility, configuration, or operational steps change.
5. Run the relevant local checks and state the results in the pull request.
6. Include generated AMD files only when the JavaScript source change requires them.

Maintainers may request changes when a contribution weakens privacy, group isolation, backward compatibility, test coverage, or documentation accuracy.

## Review and merge

A maintainer reviews every change before it is merged. Acceptance depends on scope, Moodle compatibility, test evidence, documentation, and release impact. Submitting a pull request does not guarantee inclusion or a release schedule.

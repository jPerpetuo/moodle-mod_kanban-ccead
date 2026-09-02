# Release and Operations

## Before a release

1. Confirm [version.php](../version.php) has a new monotonically increasing build number, accurate release name, maturity, and support range.
2. Update user-facing release notes and this documentation when behaviour changes.
3. Run the local preflight.
4. Push the branch and wait for the complete GitHub Actions matrix to finish successfully.
5. Test the affected flows in a Moodle staging site with developer debugging enabled.

## Staging verification

At a minimum, verify:

* shared-board activity creation and normal card movement;
* group-board activity creation with selected groups and with no groups available;
* student board access, including a rejected direct URL to an unrelated group board;
* teacher/monitor all-board access where configured;
* completed-card behaviour and the completion column;
* template creation and explicit confirmation before overwriting a board with cards;
* course import with groups included and excluded;
* course import without user data preserves columns but creates no cards;
* basic privacy export/deletion behaviour when that code changes.

## Production upgrade

1. Record the running plugin build, Moodle release, PHP version, and database engine/version.
2. Back up the database, `moodledata`, and the current plugin directory.
3. Deploy the new plugin files while preserving Moodle ownership and permissions.
4. Run Moodle's upgrade process and review the database upgrade output.
5. Purge caches and perform the targeted staging checks against production configuration.
6. Monitor Moodle PHP, web-server, cron, and task logs after deployment.

## Rollback

Do not roll back plugin files alone after a database schema change. Restore a consistent application-code and database backup pair, or apply a forward corrective release. Test the rollback process in staging before relying on it operationally.

## Fork maintenance

This repository retains the `mod_kanban` component identity of its upstream project. It can therefore replace the original plugin in an existing installation, but it cannot be listed as a separate Marketplace plugin with the same component name. Preserve upstream licence and copyright notices in every distribution.


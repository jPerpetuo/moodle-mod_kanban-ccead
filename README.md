# Kanban activity for Moodle

Kanban is a Moodle activity module for project and learning-process management inside a course. It provides shared, group, personal, and template boards with columns, cards, assignments, due dates, notifications, history, and activity-completion rules.

This repository is a maintained derivative of the original `mod_kanban` project. It preserves the `mod_kanban` Moodle component name, upstream copyright notices, and the GNU GPL v3 or later licence. It is intended to replace the original module in an existing Moodle installation. It cannot be installed alongside another plugin using the same component name.

## Supported Moodle versions

The current beta release is `0.4.0-beta`. The declared compatibility range is Moodle 4.1 through Moodle 5.2.

JavaScript is required. The activity uses Moodle reactive components and has no non-JavaScript fallback.

## Features

* Shared boards for all authorised activity users.
* One board per selected Moodle group, with server-side access control.
* Optional personal boards.
* Columns with titles, order, visual options, locking, completion behaviour, and hidden completed cards.
* Cards with descriptions, attachments, assignees, due dates, reminders, discussions, and completion state.
* Board history, notifications, and Moodle activity-completion rules.
* Template boards that copy structure only: columns, options, colours, order, and locks. Existing target cards require explicit confirmation before a template overwrites a board.
* Course import and restore that retain activity configuration and board structure without importing cards or other user data when Moodle user data is excluded.

See [Configuration and board flows](docs/flows.md) for the functional reference.

## Installation

### Install from a ZIP file

1. Download the release ZIP.
2. In Moodle, go to **Site administration > Plugins > Install plugins**.
3. Upload the ZIP and complete the validation and installation process.
4. Go to **Site administration > Notifications** if Moodle asks to complete the upgrade.

### Install from source

Place this repository at:

```
{moodle-dirroot}/mod/kanban
```

Then complete the Moodle upgrade through **Site administration > Notifications** or:

```bash
php admin/cli/upgrade.php
```

Do not install this fork alongside the original `mod_kanban` plugin. Both use the same Moodle component and directory.

## Upgrading from the original module

This fork is designed to replace the original module in place.

1. Back up the database, `moodledata`, and the existing `mod/kanban` directory.
2. Replace the existing `mod/kanban` code with the release files from this repository.
3. Run Moodle's upgrade process.
4. Purge caches and validate the affected activity flows in a staging site before production deployment.

For the complete release and rollback procedure, see [Release and operations](docs/release.md).

## Groups, permissions, and import

Group boards are limited to the groups selected in the activity settings. Users without an all-boards capability can access only boards for groups they belong to. A direct request for another group board is rejected and redirected to an accessible board.

Course import normally excludes user data. In that mode, the activity preserves board structure, including custom columns, colours, options, order, and locks, but does not import cards, attachments, assignees, discussions, or history. Groups are retained only when the Moodle import option includes them.

See [Permissions and groups](docs/permissions.md) and [Backup, restore, and import](docs/backup-restore.md).

## Documentation

The documentation index is available in [docs/README.md](docs/README.md).

* [Architecture](docs/architecture.md)
* [Configuration and board flows](docs/flows.md)
* [Permissions and groups](docs/permissions.md)
* [Backup, restore, and import](docs/backup-restore.md)
* [Development and testing](docs/development.md)
* [Release and operations](docs/release.md)

## Development and testing

The repository includes automated checks for supported Moodle versions and database engines. Before submitting changes, run the local preflight and the relevant Moodle tests.

See [Development and testing](docs/development.md) for commands, test coverage, and the GitHub Actions matrix.

## Community

* [Contributing](CONTRIBUTING.md)
* [Governance](GOVERNANCE.md)
* [Support](SUPPORT.md)
* [Security policy](SECURITY.md)

## Licence and attribution

Original work is copyright 2023-2025 ISB Bayern and Stefan Hanauska.

This derivative is distributed under the GNU General Public License, version 3 or later. See [LICENSE](LICENSE).

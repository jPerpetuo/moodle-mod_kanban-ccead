# Documentation

This directory documents the current behaviour and maintenance model of the Kanban activity module.

## Guides

| Document | Purpose |
| --- | --- |
| [Architecture](architecture.md) | Components, data model, request flow, and frontend/backend boundaries. |
| [Configuration and board flows](flows.md) | Activity modes, groups, templates, cards, columns, and completion behaviour. |
| [Permissions and groups](permissions.md) | Capabilities, board visibility, and role-design guidance. |
| [Backup, restore, and import](backup-restore.md) | What is retained or intentionally omitted when user data is excluded. |
| [Development and testing](development.md) | Local checks, automated tests, CI matrix, and code locations. |
| [Release and operations](release.md) | Upgrade, rollback, manual verification, and release discipline. |
| [0.4.0-beta release notes](releases/0.4.0-beta.md) | Release scope, compatibility, upgrade notes, and beta status. |
| [Screenshot capture guide](../screenshots/README.md) | Public screenshot scope and privacy requirements. |

## Scope and terminology

* **Activity instance**: a record in `{kanban}` linked to a Moodle course module.
* **Board**: a shared, group, personal, or template workspace for an activity.
* **Structure**: board settings and columns, including column titles, order, colours, and locks.
* **User data**: cards, attachments, descriptions, assignees, discussions, history, and user/group-specific board state.

These documents describe the current `mod_kanban` component. They are maintained alongside the code and should be updated whenever functionality, data handling, or supported Moodle versions change.

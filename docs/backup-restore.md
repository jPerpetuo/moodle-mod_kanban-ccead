# Backup, Restore, and Course Import

## Two data modes

Moodle backup and restore has a user-data setting, commonly surfaced as **Include enrolled users** or `userinfo` in backup APIs. Course import normally operates without user data.

| Data | With user data | Without user data / normal course import |
| --- | --- | --- |
| Activity settings | Restored | Restored |
| Groups | Restored only when Moodle's group option is selected | Not imported when the group option is cleared |
| Boards | Restored with user/group mapping | A structural source board is restored as an internal template |
| Columns | Restored | Restored, including title, order, options/colours, and locks |
| Cards | Restored | Not restored |
| Card descriptions and attachments | Restored | Not restored |
| Assignees, discussions, history | Restored | Not restored |

This separation prevents personal or previous-course content from becoming visible in the destination course while preserving a useful activity structure.

## Structural restore flow

When user data is excluded, the backup selects a single board as the structural source. For group mode it prefers the configured default group board, then another group board, and then the latest template. For shared mode it prefers the shared board and then the latest template.

During restore, that structural source becomes a template board in the destination activity. It contains columns but no cards. New shared/group/personal boards created in the destination inherit the template structure. This is why importing an activity preserves custom column names and colours without creating empty card shells.

## Groups in a destination course

If the Moodle import option to include groups is disabled, the activity's selected group list and preferred group are cleared. If a group-mode activity arrives in a course with no destination groups, restore safely changes it to shared-board mode.

If groups are included, Moodle maps source group identifiers to destination groups. The activity retains only mapped selected groups; it must never retain source-group identifiers or source users that do not exist in the destination course.

## Testing this behaviour

[tests/backup_restore_test.php](../tests/backup_restore_test.php) verifies the no-user-data import path: custom columns and options survive; cards do not; a destination board created from the restored template is structurally identical and empty.

## Operational guidance

Before changing backup/restore logic, test both paths: a full backup with user data and a course import without it. The second path is the most common teacher workflow and has stricter privacy expectations.


# Configuration and Board Flows

## Activity modes

| Mode | Behaviour |
| --- | --- |
| Shared board | One board is available to every user who can access the activity. |
| One board per group | Each selected Moodle group has its own group board. |
| Personal boards | Optional per-user boards, controlled by the activity setting. |

For group mode, the activity form presents selected groups and available groups using Moodle's dual-list pattern. At least one group must be selected. If the course has no groups, the form rejects group-board mode and asks the teacher to create groups or select a shared board.

## Group-board selection and access

The selected groups are stored by the activity. Only those groups are eligible for group boards. During normal viewing, users without an all-boards capability are restricted to boards for groups they belong to. A direct URL requesting a board outside that scope is rejected and redirected to an accessible board, rather than exposing the requested group board.

Users with `mod/kanban:viewallboards` or `mod/kanban:editallboards` can navigate eligible boards even when they are not members of every group. See [Permissions and groups](permissions.md).

## Columns and cards

New boards without a template receive the default columns Todo, Doing, and Done. A column can carry visual options, be locked, automatically complete cards moved into it, and hide completed cards.

Cards can be created, moved, duplicated, assigned, discussed, completed, reopened, and deleted according to capabilities. Completing a card outside a completion column moves it to the configured completion column when one is available. Creating a card inside a completion column creates it in the completed state.

The history setting records relevant card and discussion actions. History is an audit aid, not a substitute for permission checks.

## Templates and replication

Saving a board as a template copies its board configuration and columns only. Cards and their associated content are intentionally excluded.

Applying a template to one board or all configured group boards copies column titles, ordering, colours/options, and locks. It removes existing target content before applying the structure. If any target board contains cards, the interface requires explicit confirmation; content is never silently discarded.

## Completion and notifications

The activity can contribute to Moodle activity completion based on a configured number of created and/or completed cards. Cards can have due and reminder dates. Notifications are generated for supported assignment, movement, completion, discussion, and due-date events.


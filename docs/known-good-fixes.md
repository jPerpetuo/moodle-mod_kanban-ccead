# Kanban Known-Good Fixes

This file documents fixes that were validated in production-like testing and should be reused if regressions return.

## 2026-05-20 - Card footer spacing + optimistic card sync

### Problem symptoms
- Assignee name sometimes stayed "floating" above bottom in cards with icons and no due date.
- Card icons / assignee / title could flicker or disappear briefly after updates.
- Chat indicator/message could fail to appear immediately and looked inconsistent until refresh.

### Root cause
- Optimistic patches were partial in some flows, so card state could be overwritten by incomplete updates.
- Footer layout had edge cases where "meta row" existed but due date was hidden, keeping blank reserved space.
- Discussion flow depended on backend timing without immediate post-save sync.

### Applied solution
1. Use robust optimistic updates:
- Merge optimistic fields with current full card state before `processUpdates`.
- Avoid replacing card state with partial fields only.

2. Add deterministic footer compact mode:
- New state class `mod_kanban_footer_compact` for cards with:
  - assignees present,
  - meta/icons present,
  - no due date,
  - not closed.
- In this mode, assignee row and meta row are anchored to bottom with absolute positioning.

3. Strengthen discussion update flow:
- On send message:
  - apply optimistic discussion flag,
  - dispatch `sendDiscussionMessage`,
  - immediately dispatch `getDiscussionUpdates` to synchronize UI.
- If send fails, restore typed input and show exception.

4. Keep due date presence explicit in card class:
- `_dueDateFormat()` now toggles `mod_kanban_hasduedate` on the card element.
- This ensures layout rules can safely depend on a real class state.

### Files changed
- `amd/src/card.js`
- `amd/build/card.min.js`
- `styles.css`
- `templates/card.mustache`

### Validation checklist
Run these checks after deploy + cache purge:
1. Card with assignee + icon + no due date:
- assignee must stay at bottom, no blank gap.
2. Start chat and send message:
- discussion icon appears immediately and remains after F5.
3. Edit title / assign user / edit details in sequence:
- no temporary resets to wrong title and no icon disappearance.

### Recovery strategy (if regression returns)
1. Re-apply the optimistic merge strategy in `_applyOptimisticCardPatch()`.
2. Re-apply immediate `getDiscussionUpdates` after `sendDiscussionMessage`.
3. Re-apply `mod_kanban_footer_compact` CSS + `_syncFooterLayoutState()` call in:
- `stateReady()`
- `_cardUpdated()`

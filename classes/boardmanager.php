<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Class to handle updating the board
 *
 * @package    mod_kanban
 * @copyright  2023-2025 ISB Bayern
 * @author     Stefan Hanauska
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_kanban;

use cm_info;
use context_module;
use context_system;
use core_user;
use moodle_url;
use moodle_exception;
use stdClass;

/**
 * Class to handle updating the board. It also sends notifications, but does not check permissions.
 *
 * @package    mod_kanban
 * @copyright  2023-2024 ISB Bayern
 * @author     Stefan Hanauska
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class boardmanager {
    /** @var int Course module id */
    private int $cmid;

    /** @var stdClass The kanban instance record. */
    private stdClass $kanban;

    /** @var stdClass The current board */
    private stdClass $board;

    /** @var updateformatter Shared update formatter collecting all updates. */
    private updateformatter $formatter;

    /** @var cm_info Course module info */
    private cm_info $cminfo;

    /** @var stdClass Course */
    private stdClass $course;

    /**
     * Constructor
     *
     * @param int $cmid Course module id (0 if course module is not created yet)
     * @param int $boardid Board id (if 0, no board is loaded at this time)
     */
    public function __construct(int $cmid = 0, int $boardid = 0) {
        $this->cmid = $cmid;
        if ($cmid) {
            [$this->course, $this->cminfo] = get_course_and_cm_from_cmid($cmid);
            $this->load_instance($this->cminfo->instance);
        }
        $this->formatter = new updateformatter();
        if (!empty($boardid)) {
            $this->load_board($boardid);
        }
    }

    /**
     * Load a kanban instance
     *
     * @param int $instance Instance id
     * @param bool $dontloadcm Don't load course module data - only needed at instance creation time
     * @return void
     */
    public function load_instance(int $instance, bool $dontloadcm = false): void {
        global $DB;
        $this->kanban = $DB->get_record('kanban', ['id' => $instance], '*', MUST_EXIST);
        if (!$dontloadcm) {
             [$this->course, $this->cminfo] = get_course_and_cm_from_instance($this->kanban->id, 'kanban');
            $this->cmid = $this->cminfo->id;
        }
    }

    /**
     * Load a board.
     *
     * @param int $id Id of the board
     * @return void
     */
    public function load_board(int $id): void {
        $this->board = helper::get_cached_board($id);
        if (empty($this->cminfo)) {
            $this->load_instance($this->board->kanban_instance);
        }
    }

    /**
     * Get the current board record.
     *
     * @return stdClass The current board
     */
    public function get_board(): stdClass {
        return $this->board;
    }

    /**
     * Return representation of collected updates.
     *
     * @return string
     */
    public function get_formatted_updates(): string {
        return $this->formatter->get_formatted_updates();
    }

    /**
     * Get the current template for this board. If there are multiple templates, use the latest one.
     *
     * @return int Board id of the template board, 0 if none found.
     */
    public function get_template_board_id(): int {
        global $DB;
        $result = $DB->get_records(
            'kanban_board',
            ['kanban_instance' => $this->kanban->id, 'template' => 1],
            'timemodified DESC',
            'id',
            0,
            1
        );
        if (!$result) {
            // Is there a system-wide template?
            $result = $DB->get_records('kanban_board', ['kanban_instance' => 0, 'template' => 1], 'timemodified DESC', 'id', 0, 1);
        }
        if (!$result) {
            return 0;
        }
        return array_pop($result)->id;
    }

    /**
     * Creates a new user board.
     *
     * @param int $userid The user id (may not be 0, user existence is not checked)
     * @return int Id of the new board
     */
    public function create_user_board(int $userid): int {
        if (!empty($userid)) {
            return $this->create_board_from_template($this->get_template_board_id(), ['userid' => $userid, 'groupid' => 0]);
        }
        return 0;
    }

    /**
     * Creates a new group board.
     *
     * @param int $groupid The group id (may not be 0, group existence is not checked)
     * @return int Id of the new board
     */
    public function create_group_board(int $groupid): int {
        if (!empty($groupid)) {
            return $this->create_board_from_template($this->get_template_board_id(), ['userid' => 0, 'groupid' => $groupid]);
        }
        return 0;
    }

    /**
     * Saves the current board as template.
     *
     * @return int Id of the new board
     */
    public function create_template(): int {
        $this->delete_instance_templates();
        $id = $this->create_structure_template($this->board->id);
        $this->formatter->put('common', ['template' => $id]);
        return $id;
    }

    /**
     * Apply the current template to an existing board.
     *
     * This replaces all columns and cards in the target board while preserving
     * the board identity (id, owner/group scope).
     *
     * @param int $targetboardid Board id to replace.
     * @param int $templateid Template board id, defaults to the latest template.
     * @param bool $confirmoverwrite Whether the caller confirmed that cards may be removed.
     * @return void
     * @throws moodle_exception
     */
    public function apply_template_to_board(
        int $targetboardid,
        int $templateid = 0,
        bool $confirmoverwrite = false
    ): void {
        global $DB;

        if (empty($templateid)) {
            $templateid = $this->get_template_board_id();
        }
        if (empty($templateid)) {
            throw new moodle_exception('notemplateavailable', 'mod_kanban');
        }

        $template = helper::get_cached_board($templateid);
        $targetboard = helper::get_cached_board($targetboardid);
        if ((int)$template->id === (int)$targetboard->id) {
            return;
        }
        if (!$confirmoverwrite && $this->board_has_cards($targetboardid)) {
            throw new moodle_exception('templateoverwriteconfirmationrequired', 'mod_kanban');
        }

        $this->clear_board_contents($targetboardid);
        $columns = $DB->get_records('kanban_column', ['kanban_board' => $template->id]);
        $newcolumns = [];
        $now = time();
        foreach ($columns as $column) {
            $newcolumns[$column->id] = clone $column;
            $newcolumns[$column->id]->title = clean_param($column->title, PARAM_TEXT);
            $newcolumns[$column->id]->kanban_board = $targetboardid;
            $newcolumns[$column->id]->timecreated = $now;
            $newcolumns[$column->id]->timemodified = $now;
            $newcolumns[$column->id]->sequence = '';
            unset($newcolumns[$column->id]->id);
            $newcolumns[$column->id]->id = $DB->insert_record('kanban_column', $newcolumns[$column->id]);
        }

        $boardupdate = [
            'id' => $targetboardid,
            'sequence' => helper::sequence_replace($template->sequence, $newcolumns),
            'locked' => $template->locked,
            'timemodified' => $now,
        ];
        $DB->update_record('kanban_board', $boardupdate);
        helper::update_cached_board($targetboardid);
        helper::update_cached_timestamp($targetboardid, constants::MOD_KANBAN_COLUMN, $now);
        helper::update_cached_timestamp($targetboardid, constants::MOD_KANBAN_CARD, $now);
        $this->load_board($targetboardid);
    }

    /**
     * Apply the current template to all configured group boards.
     *
     * @param int $templateid Template board id, defaults to the latest template.
     * @param bool $confirmoverwrite Whether the caller confirmed that cards may be removed.
     * @return void
     * @throws moodle_exception
     */
    public function apply_template_to_all_group_boards(int $templateid = 0, bool $confirmoverwrite = false): void {
        global $DB;

        if (empty($templateid)) {
            $templateid = $this->get_template_board_id();
        }
        if (empty($templateid)) {
            throw new moodle_exception('notemplateavailable', 'mod_kanban');
        }
        $groups = $this->get_available_board_groups();
        if (empty($groups)) {
            throw new moodle_exception('nogroupavailable', 'mod_kanban');
        }
        foreach ($groups as $group) {
            $board = $DB->get_record('kanban_board', [
                'kanban_instance' => $this->kanban->id,
                'userid' => 0,
                'groupid' => (int)$group->id,
                'template' => 0,
            ]);
            if (!$confirmoverwrite && $board && $this->board_has_cards((int)$board->id)) {
                throw new moodle_exception('templateoverwriteconfirmationrequired', 'mod_kanban');
            }
        }
        foreach ($groups as $group) {
            $boardid = $this->get_or_create_board(0, (int)$group->id);
            $this->apply_template_to_board($boardid, $templateid, true);
        }
    }

    /**
     * Create a template containing only the structural configuration of a board.
     *
     * @param int $sourceboardid Board id to use as the structural source.
     * @return int Id of the new template board.
     */
    private function create_structure_template(int $sourceboardid): int {
        global $DB;

        $sourceboard = helper::get_cached_board($sourceboardid);
        $now = time();
        $template = (array)$sourceboard;
        unset($template['id']);
        $template['kanban_instance'] = $this->kanban->id;
        $template['sequence'] = '';
        $template['userid'] = 0;
        $template['groupid'] = 0;
        $template['template'] = 1;
        $template['timecreated'] = $now;
        $template['timemodified'] = $now;
        $templateid = $DB->insert_record('kanban_board', $template);
        $columns = $DB->get_records('kanban_column', ['kanban_board' => $sourceboardid]);
        $newcolumns = [];
        foreach ($columns as $column) {
            $newcolumns[$column->id] = clone $column;
            $newcolumns[$column->id]->title = clean_param($column->title, PARAM_TEXT);
            $newcolumns[$column->id]->kanban_board = $templateid;
            $newcolumns[$column->id]->sequence = '';
            $newcolumns[$column->id]->timecreated = $now;
            $newcolumns[$column->id]->timemodified = $now;
            unset($newcolumns[$column->id]->id);
            $newcolumns[$column->id]->id = $DB->insert_record('kanban_column', $newcolumns[$column->id]);
        }
        $DB->update_record('kanban_board', [
            'id' => $templateid,
            'sequence' => helper::sequence_replace($sourceboard->sequence, $newcolumns),
        ]);
        helper::update_cached_board($templateid);
        return $templateid;
    }

    /**
     * Return whether a board has cards that would be removed by applying a template.
     *
     * @param int $boardid Board id.
     * @return bool
     */
    private function board_has_cards(int $boardid): bool {
        global $DB;
        return $DB->record_exists('kanban_card', ['kanban_board' => $boardid]);
    }
    /**
     * Creates a board for the whole course.
     *
     * @return int Id of the new board
     */
    public function create_board(): int {
        return $this->create_board_from_template($this->get_template_board_id(), ['userid' => 0, 'groupid' => 0]);
    }

    /**
     * Returns an existing board for the requested scope or creates one if needed.
     *
     * @param int $userid The user id for a personal board.
     * @param int $groupid The group id for a group board.
     * @return int Id of the board.
     */
    public function get_or_create_board(int $userid = 0, int $groupid = 0): int {
        global $DB;
        $conditions = [
            'kanban_instance' => $this->kanban->id,
            'userid' => $userid,
            'groupid' => $groupid,
            'template' => 0,
        ];
        $board = $DB->get_record('kanban_board', $conditions, 'id');
        if ($board) {
            return $board->id;
        }
        if (!empty($userid)) {
            return $this->create_user_board($userid);
        }
        if (!empty($groupid)) {
            return $this->create_group_board($groupid);
        }
        return $this->create_board();
    }

    /**
     * Returns an existing board according to the activity board mode or creates one if needed.
     *
     * @param int $boardmode The board mode from the activity settings.
     * @param int $groupid The current group id, if any.
     * @return int Id of the board.
     */
    public function get_or_create_board_for_mode(int $boardmode, int $groupid = 0): int {
        if ($boardmode == constants::MOD_KANBAN_BOARDMODE_GROUP) {
            return $this->get_or_create_board(0, $groupid);
        }
        return $this->get_or_create_board();
    }

    /**
     * Return the configured group ids that may appear as boards.
     *
     * Empty means "all available groups".
     *
     * @return array<int>
     */
    public function get_configured_board_group_ids(): array {
        $serialized = trim((string)($this->kanban->boardgroups ?? ''));
        if ($serialized === '') {
            return [];
        }
        $groupids = preg_split('/[;,]/', $serialized, -1, PREG_SPLIT_NO_EMPTY);
        $groupids = array_map('intval', $groupids);
        $groupids = array_filter($groupids, function (int $groupid): bool {
            return $groupid > 0;
        });
        return array_values(array_unique($groupids));
    }

    /**
     * Return the preferred group id for opening a group board.
     *
     * This uses the first configured board group if available and otherwise
     * falls back to the legacy single default group.
     *
     * @return int
     */
    public function get_preferred_board_group_id(): int {
        $groupids = $this->get_configured_board_group_ids();
        if (!empty($groupids)) {
            return (int)reset($groupids);
        }
        return (int)($this->kanban->boardgroupid ?? 0);
    }

    /**
     * Return the boards that can be switched to from the teacher selector.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get_group_board_selector_items(): array {
        if (empty($this->cminfo)) {
            return [];
        }

        $groups = $this->get_available_board_groups();
        if (empty($groups)) {
            return [];
        }

        $items = [];
        $currentgroupid = (int)($this->board->groupid ?? 0);
        foreach ($groups as $group) {
            $items[] = [
                'id' => (int)$group->id,
                'label' => format_string($group->name),
                'url' => (new moodle_url('/mod/kanban/view.php', [
                    'id' => $this->cmid,
                    'groupid' => $group->id,
                ]))->out(false),
                'current' => (int)$group->id === $currentgroupid,
            ];
        }

        usort($items, function (array $a, array $b): int {
            return strnatcasecmp($a['label'], $b['label']);
        });

        return $items;
    }

    /**
     * Return the selector items that are relevant for the current board.
     *
     * This keeps the current board visible and adds the most useful alternative
     * boards for the current context: shared, group and personal boards.
     *
     * @param int $currentgroupid The currently selected group id, if any.
     * @param bool $includeallgroups Whether to include all allowed group boards.
     * @param bool $includeuserboards Whether to include personal boards.
     * @return array<int, array<string, mixed>>
     */
    public function get_board_selector_items(
        int $currentgroupid = 0,
        bool $includeallgroups = false,
        bool $includeuserboards = false
    ): array {
        global $USER;

        if (empty($this->board)) {
            return [];
        }

        $items = [];
        $seen = [];
        $context = \context_module::instance($this->cmid);
        $canaccessotherboards = has_capability('mod/kanban:viewallboards', $context) ||
            has_capability('mod/kanban:editallboards', $context);
        $hidecourseboard = (int)($this->kanban->boardmode ?? constants::MOD_KANBAN_BOARDMODE_SHARED) ===
            constants::MOD_KANBAN_BOARDMODE_GROUP;
        $addboard = function (int $boardid) use (&$items, &$seen, $canaccessotherboards): void {
            if (empty($boardid) || isset($seen[$boardid])) {
                return;
            }
            $seen[$boardid] = true;
            $board = helper::get_cached_board($boardid);
            if (!$canaccessotherboards && !empty($board->groupid) && !groups_is_member((int)$board->groupid)) {
                return;
            }
            if (!empty($board->groupid)) {
                $seen['group:' . (int)$board->groupid] = true;
            }
            if (!empty($board->userid)) {
                $seen['user:' . (int)$board->userid] = true;
            }
            $items[] = [
                'id' => (int)$board->id,
                'label' => $this->get_board_selector_label($board),
                'icon' => $this->get_board_selector_icon($board),
                'url' => (new moodle_url('/mod/kanban/view.php', [
                    'id' => $this->cmid,
                    'boardid' => $board->id,
                ]))->out(false),
                'current' => (int)$board->id === (int)$this->board->id,
            ];
        };

        $addgroup = function (int $groupid, string $groupname) use (&$items, &$seen): void {
            if (empty($groupid) || isset($seen['group:' . $groupid])) {
                return;
            }
            $seen['group:' . $groupid] = true;
            $items[] = [
                'id' => (int)$groupid,
                'label' => format_string($groupname),
                'icon' => 'i/group',
                'url' => (new moodle_url('/mod/kanban/view.php', [
                    'id' => $this->cmid,
                    'groupid' => $groupid,
                ]))->out(false),
                'current' => (int)$groupid === (int)($this->board->groupid ?? 0),
            ];
        };

        $addboard($this->board->id);
        if (!$hidecourseboard) {
            $addboard($this->get_or_create_board());
        }

        if (!empty($currentgroupid)) {
            $addboard($this->get_or_create_board(0, $currentgroupid));
        }

        if ($includeuserboards && !empty($USER->id)) {
            $addboard($this->get_or_create_board((int)$USER->id));
        }

        if ($includeallgroups && !empty($this->cminfo)) {
            $groups = $this->get_available_board_groups();
            foreach ($groups as $group) {
                if ((int)$group->id === (int)$currentgroupid) {
                    continue;
                }
                $addgroup((int)$group->id, (string)$group->name);
            }
        }

        usort($items, function (array $a, array $b): int {
            if (!empty($a['current'])) {
                return -1;
            }
            if (!empty($b['current'])) {
                return 1;
            }
            return strnatcasecmp($a['label'], $b['label']);
        });

        return $items;
    }

    /**
     * Return the groups that should be available for group boards.
     *
     * Users with permission to view or edit all boards must see every group
     * configured for this activity. The activity grouping is still respected,
     * so this does not expose groups outside the module's intended scope.
     *
     * @return array<int, stdClass>
     */
    public function get_available_board_groups(): array {
        $groups = [];
        $context = context_module::instance($this->cmid);
        $canaccessotherboards = has_capability('mod/kanban:viewallboards', $context) ||
            has_capability('mod/kanban:editallboards', $context);

        if ($canaccessotherboards && !empty($this->course->id)) {
            $groupingid = (int)($this->cminfo->groupingid ?? 0);
            $groups = groups_get_all_groups((int)$this->course->id, 0, $groupingid, 'g.id, g.name');
        } else if (!empty($this->cminfo)) {
            $groups = groups_get_activity_allowed_groups($this->cminfo);
        }
        if (empty($groups) && !empty($this->course->id)) {
            $groupingid = (int)($this->cminfo->groupingid ?? 0);
            $groups = groups_get_all_groups((int)$this->course->id, 0, $groupingid, 'g.id, g.name');
        }
        $configuredgroupids = $this->get_configured_board_group_ids();
        if (!empty($configuredgroupids) && !empty($groups)) {
            $groups = array_intersect_key($groups, array_flip($configuredgroupids));
        }
        return $groups ?: [];
    }

    /**
     * Build a display label for the board selector.
     *
     * @param stdClass $board The board record.
     * @return string
     */
    private function get_board_selector_label(stdClass $board): string {
        global $USER;

        if (!empty($board->template)) {
            return get_string('template', 'mod_kanban');
        }
        if (!empty($board->groupid)) {
            return groups_get_group_name((int)$board->groupid);
        }
        if (!empty($board->userid)) {
            $user = core_user::get_user((int)$board->userid);
            if ($user && $user->id === $USER->id) {
                return get_string('myuserboard', 'mod_kanban');
            }
            return get_string('userboard', 'mod_kanban', fullname($user));
        }
        return get_string('courseboard', 'mod_kanban');
    }

    /**
     * Build an icon name for the board selector.
     *
     * @param stdClass $board The board record.
     * @return string
     */
    private function get_board_selector_icon(stdClass $board): string {
        if (!empty($board->groupid)) {
            return 'i/group';
        }
        if (!empty($board->userid)) {
            return 'i/user';
        }
        return '';
    }

    /**
     * Creates a new board from a template. If no template is given or found, the default template is used.
     * Assigned users, discussions and history are not copied.
     *
     * @param int $templateid Board id of the template.
     * @param array $data Data to override in the board record
     * @return int Id of the new board
     */
    public function create_board_from_template(int $templateid = 0, array $data = []): int {
        global $DB;
        if (empty($templateid)) {
            $templateid = $this->get_template_board_id();
        }
        // Template can still not exist (if kanban instance has none). Use default template.
        if (empty($templateid)) {
            $boarddata = [
                'sequence' => '',
                'userid' => 0,
                'groupid' => 0,
                'template' => 0,
                'timecreated' => time(),
                'timemodified' => time(),
                'kanban_instance' => $this->kanban->id,
            ];
            // Replace / append data.
            $boarddata = array_merge($boarddata, $data);
            $boardid = $DB->insert_record('kanban_board', $boarddata);
            $columns = [
                get_string('todo', 'kanban') => '{}',
                get_string('doing', 'kanban') => '{}',
                get_string('done', 'kanban') => '{"autoclose": true}',
            ];
            $columnids = [];
            foreach ($columns as $columnname => $options) {
                $columnids[] = $DB->insert_record('kanban_column', [
                    'title' => clean_param($columnname, PARAM_TEXT),
                    'sequence' => '',
                    'kanban_board' => $boardid,
                    'options' => $options,
                    'timecreated' => time(),
                    'timemodified' => time(),
                ]);
            }
            $DB->update_record('kanban_board', ['id' => $boardid, 'sequence' => join(',', $columnids)]);
            helper::update_cached_board($boardid);
            return $boardid;
        } else {
            $template = helper::get_cached_board($templateid);

            // If it is a site wide template, we need system context to copy files.
            if ($template->kanban_instance == 0) {
                $context = context_system::instance();
            } else {
                $context = context_module::instance($this->cmid, 'kanban');
            }

            $newboard = (array) $template;
            // By default, new board is not a template (can be overwritten via $data).
            $newboard['template'] = 0;
            $newboard['timecreated'] = time();
            $newboard['timemodified'] = time();
            $newboard['userid'] = 0;
            $newboard['group'] = 0;
            unset($newboard['id']);

            $newboard = array_merge($newboard, $data);

            $newboard['id'] = $DB->insert_record('kanban_board', $newboard);
            $columns = $DB->get_records('kanban_column', ['kanban_board' => $template->id]);
            $cards = $DB->get_records('kanban_card', ['kanban_board' => $template->id]);
            $newcolumn = [];
            $newcard = [];
            foreach ($columns as $column) {
                $column->title = clean_param($column->title, PARAM_TEXT);
                $newcolumn[$column->id] = clone $column;
                $newcolumn[$column->id]->kanban_board = $newboard['id'];
                $newcolumn[$column->id]->timecreated = time();
                $newcolumn[$column->id]->timemodified = time();
                unset($newcolumn[$column->id]->id);
                $newcolumn[$column->id]->id = $DB->insert_record('kanban_column', $newcolumn[$column->id]);
            }
            foreach ($cards as $card) {
                $newcard[$card->id] = clone $card;
                $newcard[$card->id]->kanban_board = $newboard['id'];
                $newcard[$card->id]->timecreated = time();
                $newcard[$card->id]->timemodified = time();
                $newcard[$card->id]->kanban_column = $newcolumn[$card->kanban_column]->id;
                $newcard[$card->id]->originalid = $card->id;
                unset($newcard[$card->id]->id);
                // Remove user id of original creator.
                unset($newcard[$card->id]->createdby);
                $newcard[$card->id]->id = $DB->insert_record('kanban_card', $newcard[$card->id]);
                // Copy attachment files.
                if ($context) {
                    $this->copy_attachment_files($context->id, $card->id, $newcard[$card->id]->id);
                }
            }

            $newboard['sequence'] = helper::sequence_replace($newboard['sequence'], $newcolumn);
            $DB->update_record('kanban_board', $newboard);
            helper::update_cached_board($newboard['id']);
            foreach ($newcolumn as $col) {
                $col->sequence = helper::sequence_replace($col->sequence, $newcard);
                $DB->update_record('kanban_column', $col);
            }
            return $newboard['id'];
        }
    }

    /**
     * Delete all instance-level template boards before saving a new one.
     *
     * Site-wide templates are intentionally preserved.
     *
     * @return void
     */
    private function delete_instance_templates(): void {
        global $DB;

        $templateids = $DB->get_fieldset_select(
            'kanban_board',
            'id',
            'kanban_instance = :instance AND template = :template',
            ['instance' => $this->kanban->id, 'template' => 1]
        );

        foreach ($templateids as $templateid) {
            $this->clear_board_contents((int)$templateid);
            $DB->delete_records('kanban_board', ['id' => $templateid]);
            helper::invalidate_cached_board((int)$templateid);
        }
    }

    /**
     * Remove all columns/cards/history from a board while keeping the board record.
     *
     * @param int $boardid Board id to clear.
     * @return void
     */
    private function clear_board_contents(int $boardid): void {
        global $DB;

        $cardids = $DB->get_fieldset_select('kanban_card', 'id', 'kanban_board = :id', ['id' => $boardid]);
        if (!empty($cardids)) {
            $this->delete_cards($cardids, false);
        }

        $DB->delete_records('kanban_history', ['kanban_board' => $boardid]);
        $DB->delete_records('kanban_column', ['kanban_board' => $boardid]);
        $DB->delete_records('kanban_card', ['kanban_board' => $boardid]);
        $DB->update_record('kanban_board', [
            'id' => $boardid,
            'sequence' => '',
            'timemodified' => time(),
        ]);
        helper::update_cached_board($boardid);
    }

    /**
     * Deletes a board and all contents of it.
     *
     * @param int $id The board id
     * @return void
     */
    public function delete_board(int $id) {
        global $DB;
        try {
            $transaction = $DB->start_delegated_transaction();
            // Cards need to be read to identify files, assignees and discussions.
            $cardids = $DB->get_fieldset_select('kanban_card', 'id', 'kanban_board = :id', ['id' => $id]);
            $this->delete_cards($cardids);

            $DB->delete_records('kanban_history', ['kanban_board' => $id]);
            $DB->delete_records('kanban_column', ['kanban_board' => $id]);
            $DB->delete_records('kanban_card', ['kanban_board' => $id]);
            $DB->delete_records('kanban_board', ['id' => $id]);
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
        // The rest of the elements is skipped in the update message.
        $this->load_board($id);
        $this->formatter->delete('board', ['id' => $id]);
        helper::invalidate_cached_board($id);
    }

    /**
     * Delete multiple cards and all attached data (discussions, assignees, files, calendar events).
     *
     * @param array $ids The card ids
     * @param bool $updatecolumn Whether to update the column sequence (can be set to false, if column is going to be deleted)
     * @return void
     */
    public function delete_cards(array $ids, bool $updatecolumn = true): void {
        foreach ($ids as $id) {
            $this->delete_card($id, $updatecolumn);
        }
    }

    /**
     * Delete a card and all attached data (discussions, assignees, files, calendar events).
     *
     * @param int $cardid Card id
     * @param bool $updatecolumn Whether to update the column sequence (can be set to false, if column is going to be deleted)
     * @return void
     */
    public function delete_card(int $cardid, bool $updatecolumn = true): void {
        global $DB;
        $fs = get_file_storage();
        try {
            $transaction = $DB->start_delegated_transaction();
            $DB->delete_records('kanban_discussion_comment', ['kanban_card' => $cardid]);
            $DB->delete_records('kanban_assignee', ['kanban_card' => $cardid]);
            $context = context_module::instance($this->cmid, IGNORE_MISSING);
            $fs->delete_area_files($context->id, 'mod_kanban', 'attachments', $cardid);
            $card = $this->get_card($cardid);
            if ($updatecolumn) {
                $column = $DB->get_record('kanban_column', ['id' => $card->kanban_column]);
                $update = [
                    'id' => $column->id,
                    'timemodified' => time(),
                    'sequence' => helper::sequence_remove($column->sequence, $cardid),
                ];
                $DB->update_record('kanban_column', $update);
                $this->formatter->put('columns', $update);
                helper::update_cached_timestamp($card->kanban_board, constants::MOD_KANBAN_COLUMN);
            }
            $DB->delete_records('kanban_card', ['id' => $cardid]);
            helper::remove_calendar_event($this->kanban, (object) ['id' => $cardid]);
            // As long as history is only attached to cards, it will be deleted here.
            // ToDo if this will be changed: Replace the following line with history writer (deletion of card).
            $DB->delete_records('kanban_history', ['kanban_card' => $cardid]);
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
        $this->formatter->delete('cards', ['id' => $cardid]);
    }

    /**
     * Delete a column and all cards inside.
     *
     * @param int $id The id of the column
     * @param bool $updateboard Whether to update the board sequence (can be set to false, if board is going to be deleted)
     * @return void
     */
    public function delete_column(int $id, bool $updateboard = true): void {
        global $DB;
        $cardids = $DB->get_fieldset_select('kanban_card', 'id', 'kanban_column = :id', ['id' => $id]);
        try {
            $transaction = $DB->start_delegated_transaction();
            $this->delete_cards($cardids, false);
            $DB->delete_records('kanban_column', ['id' => $id]);
            $this->formatter->delete('columns', ['id' => $id]);
            if ($updateboard) {
                $this->board->sequence = helper::sequence_remove($this->board->sequence, $id);
                $update = ['id' => $this->board->id, 'sequence' => $this->board->sequence, 'timemodified' => time()];
                $DB->update_record('kanban_board', $update);
                helper::update_cached_board($update['id']);
                $this->formatter->put('board', $update);
            }
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Adds a new column.
     *
     * @param int $aftercol Id of the column before
     * @param array $data Data to override default values
     * @return int Id of the column (0 if no column was added)
     */
    public function add_column(int $aftercol = 0, array $data = []): int {
        global $DB;
        if (empty($this->board->locked)) {
            $defaults = [
                'title' => get_string('newcolumn', 'mod_kanban'),
                'options' => '{}',
                'locked' => 0,
            ];
            $defaultsfixed = [
                'kanban_board' => $this->board->id,
                'timecreated' => time(),
                'timemodified' => time(),
                'sequence' => '',
            ];
            $data = array_merge($defaults, $data, $defaultsfixed);

            // Sanitize title to be extra safe.
            $data['title'] = clean_param($data['title'], PARAM_TEXT);
            try {
                $transaction = $DB->start_delegated_transaction();
                $columnids = $DB->get_fieldset_select('kanban_column', 'id', 'kanban_board = :id', ['id' => $this->board->id]);
                $data['id'] = $DB->insert_record('kanban_column', $data);

                $this->board->sequence = helper::heal_missing_columns($this->board->sequence, $columnids);

                helper::update_cached_board($this->board->id);
                $update = [
                    'id' => $this->board->id,
                    'sequence' => helper::sequence_add_after($this->board->sequence, $aftercol, $data['id']),
                    'timemodified' => time(),
                ];
                $DB->update_record('kanban_board', $update);
                $transaction->allow_commit();
            } catch (\Exception $e) {
                $transaction->rollback($e);
            }
            helper::update_cached_board($update['id']);

            $this->formatter->put('board', $update);
            $this->formatter->put('columns', $data);
            return $data['id'];
        }
        return 0;
    }

    /**
     * Adds a new card.
     *
     * @param int $columnid Id of the column
     * @param int $aftercard Id of the card before (0 means to insert at top)
     * @param array $data Data to override default values
     * @return int Id of the card
     */
    public function add_card(int $columnid, int $aftercard = 0, array $data = []): int {
        global $DB, $USER;
        $defaults = [
            'title' => get_string('newcard', 'mod_kanban'),
            'options' => '{}',
            'description' => '',
            'createdby' => $USER->id,
        ];
        $defaultsfixed = [
            'kanban_board' => $this->board->id,
            'kanban_column' => $columnid,
            'timecreated' => time(),
            'timemodified' => time(),
            'sequence' => '',
        ];
        $data = array_merge($defaults, $data, $defaultsfixed);

        $data['number'] = self::get_next_card_number();

        $column = $DB->get_record('kanban_column', ['id' => $columnid]);
        $iscompletioncolumn = false;
        if ($column) {
            $iscompletioncolumn = $this->is_completion_column($column);
        }
        if ($iscompletioncolumn) {
            $data['completed'] = 1;
        }

        $data['id'] = $DB->insert_record('kanban_card', $data);
        $data['assignees'] = [];
        if ($iscompletioncolumn) {
            $data['completedat'] = $data['timemodified'];
        }
        // Sanitize title to be extra safe.
        $data['title'] = clean_param($data['title'], PARAM_TEXT);

        try {
            $transaction = $DB->start_delegated_transaction();

            $update = [
                'id' => $columnid,
                'sequence' => helper::sequence_add_after($column->sequence, $aftercard, $data['id']),
                'timemodified' => time(),
            ];
            $DB->update_record('kanban_column', $update);
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }

        // Users can always edit cards they created.
        $data['canedit'] = $this->can_user_manage_specific_card($data['id']);
        $data['columnname'] = clean_param($column->title, PARAM_TEXT);

        $this->formatter->put('cards', $data);
        $this->formatter->put('columns', $update);
        $this->write_history('added', constants::MOD_KANBAN_CARD, $data, $columnid, $data['id']);
        helper::update_cached_timestamp($this->board->id, constants::MOD_KANBAN_COLUMN, $update['timemodified']);
        helper::update_cached_timestamp($this->board->id, constants::MOD_KANBAN_CARD, $update['timemodified']);

        $this->update_completion([$USER->id]);

        return $data['id'];
    }

    /**
     * Moves a column.
     *
     * @param int $columnid Id of the column to move
     * @param int $aftercol Id of the (future) column before (0 means to move at the leftmost position)
     * @return void
     */
    public function move_column(int $columnid, int $aftercol): void {
        global $DB;
        try {
            $transaction = $DB->start_delegated_transaction();
            $column = $DB->get_record('kanban_column', ['id' => $columnid]);
            if (!$this->board->locked && !$column->locked) {
                $columnids = $DB->get_fieldset_select('kanban_column', 'id', 'kanban_board = :id', ['id' => $this->board->id]);
                $this->board->sequence = helper::heal_missing_columns($this->board->sequence, $columnids);
                $update = [
                    'id' => $this->board->id,
                    'sequence' => helper::sequence_move_after($this->board->sequence, $aftercol, $columnid),
                    'timemodified' => time(),
                ];
                $DB->update_record('kanban_board', $update);
                helper::update_cached_board($update['id']);
                $this->formatter->put('board', $update);
            }
            $transaction->allow_commit();
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Moves a card.
     *
     * @param int $cardid Id of the card to move
     * @param int $aftercard If of the card to move after (0 means move to top of the column)
     * @param int $columnid Id of the column to move to (if 0, use current column)
     * @return void
     */
    public function move_card(int $cardid, int $aftercard, int $columnid = 0): void {
        global $DB, $USER;
        $card = $this->get_card($cardid);
        if (empty($columnid)) {
            $columnid = $card->kanban_column;
        }

        try {
            $transaction = $DB->start_delegated_transaction();
            $sourcecolumn = $DB->get_record('kanban_column', ['id' => $card->kanban_column]);

            if ($card->kanban_column == $columnid) {
                $update = [
                    'id' => $columnid,
                    'sequence' => helper::sequence_move_after($sourcecolumn->sequence, $aftercard, $cardid),
                    'timemodified' => time(),
                ];
                $DB->update_record('kanban_column', $update);
                $transaction->allow_commit();
                $this->formatter->put('columns', $update);
            } else {
                $targetcolumn = $DB->get_record('kanban_column', ['id' => $columnid]);
                $targetiscompletion = $this->is_completion_column($targetcolumn);

                if (!empty($targetcolumn->locked) && !$targetiscompletion) {
                    // Force the frontend to keep the locked column state in sync.
                    $this->formatter->put('columns', [
                        'id' => $targetcolumn->id,
                        'locked' => 1,
                        'timemodified' => time(),
                    ]);
                    return;
                }

                $options = json_decode($targetcolumn->options);
                $wiplimit = $options->wiplimit ?? 0;

                if ($wiplimit > 0) {
                    self::check_wiplimit($columnid, $cardid, $wiplimit);
                }

                $sourceiscompletion = $this->is_completion_column($sourcecolumn);

                // Card needs to be processed first, because column sorting in frontend will only
                // work if card is already moved in the right position.
                $updatecard = ['id' => $cardid, 'kanban_column' => $columnid, 'timemodified' => time()];
                // If target column is the completion column, update card to be completed.
                if ($targetiscompletion) {
                    if ($card->completed) {
                        self::set_card_complete($cardid, 1);
                    }
                }
                $DB->update_record('kanban_card', $updatecard);
                // When inplace editing the title and moving the card happens quite fast in a row,
                // it might happen that the "old" title is shown in the ui since inplace editing does
                // change the DOM directly and does not trigger the update function.
                // So we add the current title here to avoid this.
                $this->formatter->put('cards', array_merge($updatecard, ['title' => clean_param($card->title, PARAM_TEXT)]));

                // Remove from current column.
                $update = [
                    'id' => $sourcecolumn->id,
                    'sequence' => helper::sequence_remove($sourcecolumn->sequence, $cardid),
                    'timemodified' => time(),
                ];
                $DB->update_record('kanban_column', $update);
                $this->formatter->put('columns', $update);

                // Add to target column.
                $update = [
                    'id' => $columnid,
                    'sequence' => helper::sequence_add_after($targetcolumn->sequence, $aftercard, $cardid),
                    'timemodified' => time(),
                ];
                $DB->update_record('kanban_column', $update);
                $transaction->allow_commit();
                $this->formatter->put('columns', $update);

                $data = array_merge((array) $card, $updatecard);
                $data['username'] = fullname($USER);
                $data['boardname'] = $this->kanban->name;
                $data['columnname'] = clean_param($targetcolumn->title, PARAM_TEXT);
                $assignees = $this->get_card_assignees($cardid);
                helper::send_notification($this->cminfo, 'moved', $assignees, (object) $data);
                if ($targetiscompletion && $card->completed == 0) {
                    self::set_card_complete($cardid, 1);
                } else if ($sourceiscompletion && !$targetiscompletion && !empty($card->completed)) {
                    // Reopen card when it leaves the completion column.
                    self::set_card_complete($cardid, 0);
                }
                $this->write_history(
                    'moved',
                    constants::MOD_KANBAN_CARD,
                    ['columnname' => clean_param($targetcolumn->title, PARAM_TEXT)],
                    $card->kanban_column,
                    $cardid
                );
                helper::update_cached_timestamp($this->board->id, constants::MOD_KANBAN_CARD, $update['timemodified']);
            }
        } catch (\Exception $e) {
            $transaction->rollback($e);
        }
        helper::update_cached_timestamp($this->board->id, constants::MOD_KANBAN_COLUMN, $update['timemodified']);
    }

    /**
     * Checks whether the WIP limit is reached for a certain column and card. Raises an exception if limit is reached.
     * @param int $columnid Id of the column
     * @param int $cardid Id of the current card
     * @param int $wiplimit WIP limit
     * @param array $assignees Array of user ids that should be checked for WIP limit. If empty, checking will be done
     *                         for the current assignees.
     * @throws moodle_exception
     */
    public function check_wiplimit(int $columnid, int $cardid, int $wiplimit, array $assignees = []): void {
        if (empty($assignees)) {
            $assignees = $this->get_card_assignees($cardid);
        }
        $overlimit = [];
        foreach ($assignees as $assignee) {
            $wip = $this->get_wip($columnid, $assignee, $cardid);
            if ($wip >= $wiplimit) {
                $user = core_user::get_user($assignee);
                $overlimit[] = fullname($user);
            }
        }
        if (count($overlimit) > 0) {
            throw new moodle_exception('wiplimitreached', 'mod_kanban', '', ['users' => implode(', ', $overlimit)]);
        }
    }

    /**
     * Returns the number of cards in a column a certain user is currently assigned to.
     * @param int $columnid Id of the column
     * @param int $userid Id of the user
     * @param int $cardtoexclude Id of a card to exclude from the count
     */
    public function get_wip(int $columnid, int $userid, int $cardtoexclude = 0): int {
        global $DB;
        $count = $DB->get_field_sql(
            'SELECT COUNT(*)
            FROM {kanban_card} c
            INNER JOIN {kanban_assignee} a
            ON a.kanban_card = c.id
            WHERE a.userid = :userid AND c.kanban_column = :columnid AND c.id != :cardid',
            ['columnid' => $columnid, 'userid' => $userid, 'cardid' => $cardtoexclude]
        );
        return $count;
    }

    /**
     * Assigns a user to a card.
     *
     * @param int $cardid Id of the card
     * @param int $userid Id of the user
     * @return void
     */
    public function assign_user(int $cardid, int $userid): void {
        global $DB, $OUTPUT, $USER;
        $card = $this->get_card($cardid);
        $column = $this->get_column($card->kanban_column);
        $options = json_decode($column->options);
        $wiplimit = $options->wiplimit ?? 0;

        if ($wiplimit > 0) {
            self::check_wiplimit($card->kanban_column, $cardid, $wiplimit, [$userid]);
        }

        $DB->insert_record('kanban_assignee', ['kanban_card' => $cardid, 'userid' => $userid]);

        $update = [
            'id' => $cardid,
            'timemodified' => time(),
        ];
        $DB->update_record('kanban_card', $update);

        helper::add_or_update_calendar_event($this->kanban, $card, [$userid]);

        $userids = $this->get_card_assignees($cardid);

        $user = \core_user::get_user($userid);
        $this->formatter->put('users', [
            'id' => $user->id,
            'fullname' => fullname($user),
            'userpicture' => $OUTPUT->user_picture($user, ['link' => false]),
        ]);

        $update['assignees'] = $userids;
        $update['selfassigned'] = in_array($USER->id, $userids);
        $update['canedit'] = $this->can_user_manage_specific_card($card->id);
        $this->formatter->put('cards', $update);

        $this->write_history('assigned', constants::MOD_KANBAN_CARD, ['userid' => $userid], $card->kanban_column, $cardid);
        helper::update_cached_timestamp($this->board->id, constants::MOD_KANBAN_CARD, $update['timemodified']);
        if (!empty($card->completed)) {
            $this->update_completion([$userid]);
        }
    }

    /**
     * Unassigns a user from a card.
     *
     * @param int $cardid Id of the card
     * @param int $userid Id of the user
     * @return void
     */
    public function unassign_user(int $cardid, int $userid): void {
        global $DB, $USER;
        $DB->delete_records('kanban_assignee', ['kanban_card' => $cardid, 'userid' => $userid]);
        $card = $this->get_card($cardid);
        $update = [
            'id' => $cardid,
            'timemodified' => time(),
        ];
        $DB->update_record('kanban_card', $update);

        helper::remove_calendar_event($this->kanban, (object) ['id' => $cardid], [$userid]);

        $userids = $this->get_card_assignees($cardid);
        $userids = array_unique($userids);

        $update['assignees'] = $userids;
        $update['selfassigned'] = in_array($USER->id, $userids);
        $update['canedit'] = $this->can_user_manage_specific_card($card->id);
        $this->formatter->put('cards', $update);
        $this->write_history('unassigned', constants::MOD_KANBAN_CARD, ['userid' => $userid], $card->kanban_column, $cardid);
        helper::update_cached_timestamp($this->board->id, constants::MOD_KANBAN_CARD, $update['timemodified']);
        if (!empty($card->completed)) {
            $this->update_completion([$userid]);
        }
    }

    /**
     * Changes completion state of a card.
     *
     * @param int $cardid Id of the card
     * @param int $state State
     * @return void
     */
    public function set_card_complete(int $cardid, int $state): void {
        global $DB, $USER;
        $card = $this->get_card($cardid);
        $update = ['id' => $cardid, 'completed' => $state, 'timemodified' => time(), 'repeat_enable' => 0];
        $updateforfrontend = $update;
        $updateforfrontend['completedat'] = !empty($state) ? $update['timemodified'] : 0;
        $this->formatter->put('cards', $updateforfrontend);
        $DB->update_record('kanban_card', $update);
        $assignees = $this->get_card_assignees($cardid);
        if ($state) {
            helper::remove_calendar_event($this->kanban, $card, $assignees);
            if (!empty($card->repeat_enable)) {
                $newcard = clone $card;
                $newcard->discussion = 0;
                if ($card->repeat_newduedate == constants::MOD_KANBAN_REPEAT_NONEWDUEDATE) {
                    $newcard->duedate = 0;
                    $newcard->reminder = 0;
                } else {
                    $timedifference = $newcard->duedate - $newcard->reminder;
                    $timebase = (
                        $card->repeat_newduedate == constants::MOD_KANBAN_REPEAT_NEWDUEDATE_AFTERDUE && !empty($newcard->duedate) ?
                        $newcard->duedate :
                        time()
                    );
                    $newcard->duedate = strtotime(
                        '+' .
                        $card->repeat_interval .
                        ' ' .
                        constants::MOD_KANBAN_REPEAT_INTERVAL_TYPE[$card->repeat_interval_type],
                        $timebase
                    );
                    $newcard->reminder = $newcard->duedate - $timedifference;
                }
                $this->add_card($this->get_leftmost_column($card->kanban_board), 0, (array)$newcard);
            }
        } else {
            helper::add_or_update_calendar_event($this->kanban, $card, $assignees);
        }
        $card->username = fullname($USER);
        $card->boardname = $this->kanban->name;
        helper::send_notification($this->cminfo, 'closed', $assignees, $card, ($state == 0 ? 'reopened' : null));
        $this->update_completion($assignees);
        $this->write_history(
            ($state == 0 ? 'reopened' : 'completed'),
            constants::MOD_KANBAN_CARD,
            $update,
            $card->kanban_column,
            $cardid
        );
        helper::update_cached_timestamp($this->board->id, constants::MOD_KANBAN_CARD, $update['timemodified']);
    }

    /**
     * Changes lock state of a column.
     *
     * @param int $columnid Id of the column
     * @param int $state State
     * @return void
     */
    public function set_column_locked(int $columnid, int $state): void {
        global $DB;
        $update = ['id' => $columnid, 'locked' => $state, 'timemodified' => time()];
        $DB->update_record('kanban_column', $update);
        $this->formatter->put('columns', $update);
        helper::update_cached_timestamp($this->board->id, constants::MOD_KANBAN_COLUMN, $update['timemodified']);
    }

    /**
     * Changes lock state of all board columns.
     *
     * @param int $state State
     * @return void
     */
    public function set_board_columns_locked(int $state): void {
        global $DB;
        $columns = $DB->get_fieldset_select('kanban_column', 'id', 'kanban_board = :id', ['id' => $this->board->id]);
        $update = ['id' => $this->board->id, 'locked' => $state, 'timemodified' => time()];
        $DB->update_record('kanban_board', $update);
        helper::update_cached_board($update['id']);
        $this->formatter->put('board', $update);
        foreach ($columns as $col) {
            $this->set_column_locked($col, $state);
        }
    }

    /**
     * Add a message to a card discussion.
     *
     * @param int $cardid Id of the card
     * @param string $message Message
     * @return void
     */
    public function add_discussion_message(int $cardid, string $message): void {
        global $DB, $USER;
        $card = $this->get_card($cardid);
        $update = ['kanban_card' => $cardid, 'content' => $message, 'userid' => $USER->id, 'timecreated' => time()];
        $update['id'] = $DB->insert_record('kanban_discussion_comment', $update);
        $update['candelete'] = true;
        $update['username'] = fullname($USER);
        if (!empty($this->kanban->usenumbers) && !empty($this->kanban->linknumbers)) {
            $update['content'] = numberfilter::filter($update['content']);
        }
        $update['content'] = format_text($update['content'], FORMAT_HTML);
        $this->formatter->put('discussions', $update, false);
        $update['content'] = $message;

        if (empty($card->discussion)) {
            $updatecard = ['id' => $cardid, 'discussion' => 1, 'timemodified' => time()];
            $DB->update_record('kanban_card', $updatecard);
            $this->formatter->put('cards', $updatecard);
            helper::update_cached_timestamp($this->board->id, constants::MOD_KANBAN_CARD, $updatecard['timemodified']);
        }

        $update['boardname'] = $this->kanban->name;
        $update['title'] = clean_param($card->title, PARAM_TEXT);
        $assignees = $this->get_card_assignees($cardid);
        helper::send_notification($this->cminfo, 'discussion', $assignees, (object) $update);
        // Do not write username to history.
        unset($update['username']);
        $this->write_history('added', constants::MOD_KANBAN_DISCUSSION, $update, $card->kanban_column, $cardid);
    }

    /**
     * Delete a message from a discussion.
     *
     * @param int $messageid Id of the message
     * @param int $cardid Id of the card
     * @return void
     */
    public function delete_discussion_message(int $messageid, int $cardid): void {
        global $DB;
        $card = $this->get_card($cardid);
        $update = ['id' => $messageid];
        $DB->delete_records('kanban_discussion_comment', $update);
        $this->formatter->delete('discussions', $update);
        $this->write_history('deleted', constants::MOD_KANBAN_DISCUSSION, $update, $card->kanban_column, $cardid);
        if (!$DB->record_exists('kanban_discussion_comment', ['kanban_card' => $cardid])) {
            $update = ['id' => $cardid, 'discussion' => 0, 'timemodified' => time()];
            $DB->update_record('kanban_card', $update);
            $this->formatter->put('cards', $update);
            helper::update_cached_timestamp($this->board->id, constants::MOD_KANBAN_CARD, $update['timemodified']);
        }
    }

    /**
     * Updates a card with the given values.
     *
     * @param int $cardid Id of the card
     * @param array $data Data to update
     * @return void
     */
    public function update_card(int $cardid, array $data): void {
        global $DB, $OUTPUT, $USER;
        $context = context_module::instance($this->cmid);
        $cardkeys = [
            'id',
            'title',
            'description',
            'descriptionformat',
            'duedate',
            'reminderdate',
            'options',
            'kanban_column',
            'kanban_board',
            'completed',
            'repeat_enable',
            'repeat_interval',
            'repeat_interval_type',
            'repeat_newduedate',
            'background',
        ];
        // Do some extra sanitizing.
        if (isset($data['title'])) {
            $data['title'] = s($data['title']);
            if (trim((string) $data['title']) === '') {
                $data['title'] = get_string('newcard', 'mod_kanban');
            }
        }
        if (isset($data['description'])) {
            $data['description'] = clean_param($data['description'], PARAM_CLEANHTML);
        }
        if (isset($data['options'])) {
            $data['options'] = helper::sanitize_json_string($data['options']);
        }
        $allowedcardcolors = [
            '#FFFFFF',
            '#F6EEB9',
            '#F8D0AF',
            '#F7BBC0',
            '#EFC2E9',
            '#D5C8F6',
            '#D2E3FA',
            '#A9E5E1',
            '#E5F2BF',
        ];
        $selectedbackground = '';
        if (!empty($data['currentcolor'])) {
            $selectedbackground = strtoupper(clean_param($data['currentcolor'], PARAM_TEXT));
        } else if (!empty($data['color'])) {
            $selectedbackground = strtoupper(clean_param($data['color'], PARAM_TEXT));
        }
        if ($selectedbackground !== '') {
            $selectedbackground = ltrim($selectedbackground, '#');
            if (preg_match('/^[0-9A-F]{6}$/', $selectedbackground)) {
                $selectedbackground = '#' . $selectedbackground;
            }
        }
        if (!empty($selectedbackground) && in_array($selectedbackground, $allowedcardcolors, true)) {
            $data['color'] = $selectedbackground;
            $data['options'] = json_encode(['background' => $selectedbackground]);
            $data['background'] = $selectedbackground;
        }
        $card = (array) $this->get_card($cardid);
        $cardupdate = [];
        foreach ($cardkeys as $key) {
            if (!isset($data[$key])) {
                continue;
            }
            if ($card[$key] != $data[$key]) {
                $cardupdate[$key] = $data[$key];
            }
        }
        if (!empty($selectedbackground) && in_array($selectedbackground, $allowedcardcolors, true)) {
            $cardupdate['options'] = json_encode(['background' => $selectedbackground]);
            $cardupdate['background'] = $selectedbackground;
        }
        $cardupdate['id'] = $cardid;
        $cardupdate['timemodified'] = time();
        if (count($cardupdate) > 2) {
            $DB->update_record('kanban_card', $cardupdate);
        }
        $carddata = array_merge($card, $cardupdate);
        $carddata['username'] = fullname($USER);
        $carddata['boardname'] = $this->kanban->name;
        if (isset($data['assignees'])) {
            $assignees = $data['assignees'];
            $currentassignees = $this->get_card_assignees($cardid);
            $toinsert = array_diff($assignees, $currentassignees);
            $todelete = array_diff($currentassignees, $assignees);

            helper::add_or_update_calendar_event($this->kanban, (object) $carddata, $assignees);
            if (!empty($todelete)) {
                helper::remove_calendar_event($this->kanban, (object) $carddata, $todelete);
                [$sql, $params] = $DB->get_in_or_equal($todelete, SQL_PARAMS_NAMED);
                $sql = 'kanban_card = :cardid AND userid ' . $sql;
                $params['cardid'] = $cardid;
                $DB->delete_records_select('kanban_assignee', $sql, $params);
                helper::send_notification($this->cminfo, 'assigned', $todelete, (object) $carddata, 'unassigned');
                foreach ($todelete as $user) {
                    $this->write_history(
                        'unassigned',
                        constants::MOD_KANBAN_CARD,
                        ['userid' => $user],
                        $card['kanban_column'],
                        $card['id']
                    );
                }
            }
            if (!empty($card['completed'])) {
                $this->update_completion($todelete);
            }
            if (!empty($toinsert) || !empty($todelete)) {
                $cardupdate['assignees'] = $assignees;
            }
            $assignees = [];

            $columnid = $cardupdate['kanban_column'] ?? $card['kanban_column'];
            $column = $this->get_column($columnid);
            $options = json_decode($column->options);
            $wiplimit = $options->wiplimit ?? 0;

            if ($wiplimit > 0) {
                self::check_wiplimit($columnid, $cardid, $wiplimit, $toinsert);
            }

            foreach ($toinsert as $assignee) {
                $assignees[] = ['kanban_card' => $cardid, 'userid' => $assignee];
                $user = \core_user::get_user($assignee);
                $this->formatter->put('users', [
                        'id' => $user->id,
                        'fullname' => fullname($user),
                        'userpicture' => $OUTPUT->user_picture($user, ['link' => false]),
                    ]);
            }
            $DB->insert_records('kanban_assignee', $assignees);
            helper::send_notification(
                $this->cminfo,
                'assigned',
                $toinsert,
                (object) array_merge($carddata, ['boardname' => $this->cminfo->name])
            );
            if (!empty($card['completed'])) {
                $this->update_completion($toinsert);
            }
            foreach ($toinsert as $user) {
                $this->write_history(
                    'assigned',
                    constants::MOD_KANBAN_CARD,
                    ['userid' => $user],
                    $card['kanban_column'],
                    $card['id']
                );
            }
        }
        $cardupdate['attachments'] = helper::get_attachments($context->id, $cardid);
        $cardupdate['hasattachment'] = count($cardupdate['attachments']) > 0;
        $cardupdate['hasdescription'] = !empty(trim($cardupdate['description'])) || $cardupdate['hasattachment'];
        if (!empty($cardupdate['description'])) {
            $cardupdate['description'] = file_rewrite_pluginfile_urls(
                $cardupdate['description'],
                'pluginfile.php',
                $context->id,
                'mod_kanban',
                'attachments',
                $cardupdate['id']
            );
        }
        $cardupdate['canedit'] = $this->can_user_manage_specific_card($cardupdate['id']);

        $this->write_history(
            'updated',
            constants::MOD_KANBAN_CARD,
            array_merge(['title' => clean_param($card['title'], PARAM_TEXT)], $cardupdate),
            $card['kanban_column'],
            $card['id']
        );
        helper::update_cached_timestamp($this->board->id, constants::MOD_KANBAN_CARD, $cardupdate['timemodified']);

        if (!empty($this->kanban->usenumbers) && !empty($this->kanban->linknumbers)) {
            if (isset($cardupdate['description'])) {
                $cardupdate['description'] = numberfilter::filter($cardupdate['description']);
            }
        }

        $currentassignees = $this->get_card_assignees($cardid);
        $cardupdate['assignees'] = $currentassignees;
        $cardupdate['selfassigned'] = in_array($USER->id, $currentassignees);

        $this->formatter->put('cards', $cardupdate, false);
    }

    /**
     * Updates a column with the given values.
     *
     * @param int $columnid Id of the column
     * @param array $data Data to update
     * @return void
     */
    public function update_column(int $columnid, array $data): void {
        global $DB;
        $column = $this->get_column($columnid);
        $alloweddotcolors = [
            '#9AA4B2',
            '#3579DC',
            '#4DB56A',
            '#7C6ED6',
            '#1D74A6',
            '#009688',
            '#C68A2E',
            '#B96A55',
            '#A9597A',
            '#7A7A2E',
        ];
        $dotcolor = '';
        if (!empty($data['dotcolor'])) {
            $candidate = strtoupper(clean_param($data['dotcolor'], PARAM_TEXT));
            if (in_array($candidate, $alloweddotcolors)) {
                $dotcolor = $candidate;
            }
        }
        $options = [
            'autoclose' => !empty($data['autoclose']),
            'autohide' => !empty($data['autohide']),
            'wiplimit' => empty($data['wiplimitenable']) ? 0 : $data['wiplimit'],
            'dotcolor' => $dotcolor,
        ];
        if (isset($data['title'])) {
            $data['title'] = s($data['title']);
        }
        $columndata = [
            'id' => $columnid,
            'title' => $data['title'],
            'options' => helper::sanitize_json_string(json_encode($options)),
            'timemodified' => time(),
        ];

        $DB->update_record('kanban_column', $columndata);

        $this->formatter->put('columns', $columndata);

        helper::update_cached_timestamp($this->board->id, constants::MOD_KANBAN_COLUMN, $columndata['timemodified']);

        if ($column->title != $columndata['title']) {
            $this->write_history('updated', constants::MOD_KANBAN_COLUMN, $columndata, $columnid);
        }
    }

    /**
     * Push a copy of this card to other boards. If target boards array is empty, card is pushed to all boards in this kanban
     * activity (including templates) to the leftmost column (if there is none, card is not copied). If there is already a copy
     * of this card, it is replaced. History, assignees and discussion are not copied.
     * For now, only boards inside the same kanban are supported.
     *
     * @param int $cardid Id of the card to push
     * @param array $boardids Array of ids of the target boards
     * @return void
     */
    public function push_card_copy(int $cardid, array $boardids = []): void {
        global $DB;
        $allboardids = $DB->get_fieldset_select('kanban_board', 'id', 'kanban_instance = :id', ['id' => $this->kanban->id]);
        if (empty($boards)) {
            $boardids = $allboardids;
        } else {
            $boardids = array_intersect($boards, $allboardids);
        }
        $card = $this->get_card($cardid);
        $originalboard = $card->kanban_board;
        unset($card->id);
        unset($card->createdby);
        unset($card->kanban_board);
        unset($card->kanban_column);
        unset($card->completed);
        unset($card->discussion);
        $card->originalid = $cardid;
        $card->timemodified = time();

        $context = context_module::instance($this->cmid, 'kanban');

        foreach ($boardids as $boardid) {
            if ($originalboard == $boardid) {
                continue;
            }
            $existingcard = $DB->get_record('kanban_card', ['kanban_board' => $boardid, 'originalid' => $cardid]);
            if (!$existingcard) {
                $sequence = $DB->get_field('kanban_board', 'sequence', ['id' => $boardid]);
                if (!$sequence) {
                    continue;
                } else {
                    $columnids = explode(',', $sequence, 2);
                    $newcard = (array) $card;
                    $newcard['kanban_column'] = $columnids[0];
                    $newcard['kanban_board'] = $boardid;
                    $newcard['timecreated'] = time();
                    $newcard['timemodified'] = time();
                    unset($newcard['id']);
                    $newcard['id'] = $DB->insert_record('kanban_card', $newcard);
                    $this->copy_attachment_files($context->id, $cardid, $newcard['id']);
                    $column = $DB->get_record('kanban_column', ['id' => $columnids[0]]);
                    $DB->update_record(
                        'kanban_column',
                        [
                            'id' => $columnids[0],
                            'sequence' => helper::sequence_add_after($column->sequence, 0, $newcard['id']),
                            'timemodified' => time(),
                        ]
                    );
                    $newcard['columnname'] = $column->title;
                    $this->write_history('added', constants::MOD_KANBAN_CARD, $newcard, $newcard['kanban_column']);
                    helper::update_cached_timestamp($boardid, constants::MOD_KANBAN_CARD, $newcard['timemodified']);
                    helper::update_cached_timestamp($boardid, constants::MOD_KANBAN_COLUMN, $newcard['timemodified']);
                }
            } else {
                $newcard = array_merge((array) $existingcard, (array) $card, ['timemodified' => time()]);
                $DB->update_record('kanban_card', $newcard);
                $this->copy_attachment_files($context->id, $cardid, $newcard['id']);
                $this->write_history('updated', constants::MOD_KANBAN_CARD, $newcard, $newcard['kanban_column']);
                helper::update_cached_timestamp($boardid, constants::MOD_KANBAN_CARD, $newcard['timemodified']);
            }
        }
    }

    /**
     * Returns the ids of all users assignees to a card.
     *
     * @param int $cardid Id of the card
     * @return array Array of userids
     */
    public function get_card_assignees(int $cardid): array {
        global $DB;
        return array_unique($DB->get_fieldset_select('kanban_assignee', 'userid', 'kanban_card = :id', ['id' => $cardid]));
    }

    /**
     * Get a card record.
     *
     * @param int $cardid Id of the card
     * @return stdClass
     */
    public function get_card(int $cardid): stdClass {
        global $DB;
        return $DB->get_record('kanban_card', ['id' => $cardid], '*', MUST_EXIST);
    }

    /**
     * Get a column record.
     *
     * @param int $columnid Id of the card
     * @return stdClass
     */
    public function get_column(int $columnid): stdClass {
        global $DB;
        return $DB->get_record('kanban_column', ['id' => $columnid], '*', MUST_EXIST);
    }

    /**
     * Get a discussion record.
     *
     * @param int $messageid Id of the message
     * @return stdClass
     */
    public function get_discussion_message(int $messageid): stdClass {
        global $DB;
        return $DB->get_record('kanban_discussion_comment', ['id' => $messageid], '*', MUST_EXIST);
    }

    /**
     * Get cm_info object to current instance.
     *
     * @return cm_info
     */
    public function get_cminfo(): cm_info {
        return $this->cminfo;
    }

    /**
     * Writes a record to the history table.
     *
     * @param string $action Action for history
     * @param int $type Type of object affected by the entry
     * @param array $data Array of data to write
     * @param int $columnid Id of the column
     * @param int $cardid Id of the card
     */
    public function write_history(string $action, int $type, array $data = [], int $columnid = 0, int $cardid = 0): void {
        global $DB, $USER;

        if (empty($this->kanban->history)) {
            return;
        }

        $affecteduser = null;
        // Affected user must be written to a separate column (for privacy provider).
        if (!empty($data['userid'])) {
            $affecteduser = $data['userid'];
        }
        // Prevent data to be accidentially saved to parameters json.
        unset($data['userid']);
        unset($data['username']);
        // Unset unused data.
        unset($data['timemodified']);
        unset($data['timecreated']);
        unset($data['createdby']);
        unset($data['canedit']);
        unset($data['id']);
        $record = [
            'action' => $action,
            'kanban_board' => $this->board->id,
            'userid' => $USER->id,
            'kanban_column' => $columnid,
            'kanban_card' => $cardid,
            'parameters' => helper::sanitize_json_string(json_encode($data)),
            'affected_userid' => $affecteduser,
            'timestamp' => time(),
            'type' => $type,
        ];
        $DB->insert_record('kanban_history', $record);
    }

    /**
     * Update completion state
     *
     * @param array $users Array of userids or user records (if empty, current user is used)
     * @return void
     */
    public function update_completion(array $users = []): void {
        global $USER;
        if (empty($users)) {
            $users = [$USER->id];
        }
        if ($this->custom_completion_enabled()) {
            $completion = new \completion_info($this->course);
            foreach ($users as $user) {
                if (is_object($user)) {
                    $completion->update_state($this->cminfo, COMPLETION_UNKNOWN, $user->id);
                } else {
                    $completion->update_state($this->cminfo, COMPLETION_UNKNOWN, $user);
                }
            }
        }
    }

    /**
     * Whether the custom completion rules are enabled for this board.
     *
     * @return bool
     */
    public function custom_completion_enabled(): bool {
        return !empty($this->kanban->completioncreate) || !empty($this->kanban->completioncomplete);
    }

    /**
     * Copy attachment files from one card to another (works only inside the same kanban instance). Overwrites files that have
     * the same filename.
     *
     * @param int $contextid Context id of the instance
     * @param int $cardid Card id (original)
     * @param int $newcardid Card id (target)
     * @return void
     */
    public function copy_attachment_files(int $contextid, int $cardid, int $newcardid): void {
        $fs = get_file_storage();
        $attachments = $fs->get_area_files($contextid, 'mod_kanban', 'attachments', $cardid, 'filename', false);
        foreach ($attachments as $attachment) {
            $existingfile = $fs->get_file(
                $contextid,
                'mod_kanban',
                'attachments',
                $newcardid,
                $attachment->get_filepath(),
                $attachment->get_filename()
            );
            if ($existingfile) {
                $existingfile->delete();
            }
            $fs->create_file_from_storedfile(['itemid' => $newcardid], $attachment);
        }
    }

    /**
     * Checks whether a user can manage a specific card.
     * @param int $cardid Id of the card
     * @param int $userid Id of the user (defaults to 0, then current user is used)
     * @return bool true if the user can manage a specific card, false otherwise
     */
    public function can_user_manage_specific_card(int $cardid, int $userid = 0): bool {
        global $USER;

        if (empty($userid)) {
            $userid = $USER->id;
        }

        $context = context_module::instance($this->cmid);
        if (has_capability('mod/kanban:manageallcards', $context, $userid)) {
            return true;
        }

        $card = $this->get_card($cardid);

        if ($card->createdby == $userid) {
            return true;
        }

        if (
            has_capability('mod/kanban:manageassignedcards', $context, $userid)
        ) {
            $assignees = $this->get_card_assignees($card->id);
            if (empty($assignees) || in_array($userid, $assignees)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the leftmost column of a board, 0 if none is found.
     *
     * @param int $boardid Id of the board, defaults to 0 (current board)
     * @return int
     */
    public function get_leftmost_column(int $boardid = 0): int {
        global $DB;
        if (empty($boardid) || $this->board->id == $boardid) {
            $sequence = $this->board->sequence;
        } else {
            $sequence = $DB->get_field('kanban_board', 'sequence', ['id' => $boardid]);
        }
        if (empty($sequence)) {
            return 0;
        }
        $columnids = explode(',', $sequence, 2);
        return $columnids[0];
    }

    /**
     * Returns the first autoclose column of a board, 0 if none is found.
     *
     * @param int $boardid Id of the board, defaults to 0 (current board)
     * @return int
     */
    public function get_first_autoclose_column(int $boardid = 0): int {
        return $this->get_first_completion_column($boardid);
    }

    /**
     * Returns the first completion column of a board, 0 if none is found.
     *
     * @param int $boardid Id of the board, defaults to 0 (current board)
     * @return int
     */
    public function get_first_completion_column(int $boardid = 0): int {
        global $DB;

        if (empty($boardid) || $this->board->id == $boardid) {
            $sequence = $this->board->sequence;
        } else {
            $sequence = $DB->get_field('kanban_board', 'sequence', ['id' => $boardid]);
        }

        if (empty($sequence)) {
            return 0;
        }

        $columnids = explode(',', $sequence);
        $fallback = 0;
        $donevalue = clean_param(get_string('done', 'kanban'), PARAM_TEXT);
        foreach ($columnids as $columnid) {
            if (empty($columnid)) {
                continue;
            }

            $column = $this->get_column((int) $columnid);
            $fallback = (int) $columnid;
            if ($this->is_completion_column($column)) {
                return (int) $columnid;
            }

            $columntitle = clean_param(html_entity_decode($column->title ?? '', ENT_COMPAT, 'UTF-8'), PARAM_TEXT);
            if (!empty($donevalue) && $columntitle === $donevalue) {
                return (int) $columnid;
            }

            if (!empty($column->locked)) {
                continue;
            }
        }

        return $fallback;
    }

    /**
     * Checks whether the given column should behave as the completion column.
     *
     * @param stdClass $column Column record
     * @return bool
     */
    private function is_completion_column(stdClass $column): bool {
        $options = json_decode($column->options ?? '{}');
        if (!empty($options->autoclose)) {
            return true;
        }

        $donevalue = clean_param(get_string('done', 'kanban'), PARAM_TEXT);
        $columntitle = clean_param(html_entity_decode($column->title ?? '', ENT_COMPAT, 'UTF-8'), PARAM_TEXT);
        return !empty($donevalue) && $columntitle === $donevalue;
    }

    /**
     * Returns the last card of a column, 0 if none is found.
     *
     * @param int $columnid Id of the column
     * @return int
     */
    public function get_last_card_in_column(int $columnid): int {
        $column = $this->get_column($columnid);

        if (empty($column->sequence)) {
            return 0;
        }

        $cardids = array_values(array_filter(explode(',', $column->sequence)));
        if (empty($cardids)) {
            return 0;
        }

        return (int) end($cardids);
    }

    /**
     * Duplicates a card.
     *
     * @param int $cardid Id of the card to duplicate
     * @return int Id of the new card
     */
    public function duplicate_card(int $cardid): int {
        global $USER;
        $card = $this->get_card($cardid);
        $card->createdby = $USER->id;
        $card->discussion = 0;
        $newcardid = $this->add_card($card->kanban_column, $card->id, (array) $card);
        $this->copy_attachment_files($this->cminfo->context->id, $cardid, $newcardid);
        return $newcardid;
    }

    /**
     * Returns the next card number for a board.
     *
     * @param int $boardid Id of the board
     * @return int Next card number
     */
    public function get_next_card_number(int $boardid = 0): int {
        global $DB;
        if (empty($boardid)) {
            $boardid = $this->board->id;
        }
        $nextnumber = $DB->get_field('kanban_card', 'MAX(number)+1', ['kanban_board' => $boardid]);
        return empty($nextnumber) ? 1 : $nextnumber;
    }

    /**
     * Returns the current kanban instance.
     * @return stdClass Kanban instance
     */
    public function get_instance(): stdClass {
        return $this->kanban;
    }
}

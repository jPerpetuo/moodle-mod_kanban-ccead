<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * View a kanban instance
 *
 * @package     mod_kanban
 * @copyright   2023-2024 ISB Bayern
 * @author      Stefan Hanauska <stefan.hanauska@csg-in.de>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');
require_once('lib.php');

use mod_kanban\boardmanager;
use mod_kanban\constants;
use mod_kanban\helper;

$id = required_param('id', PARAM_INT);
$boardid = optional_param('boardid', 0, PARAM_INT);
$requestedgroupid = optional_param('groupid', 0, PARAM_INT);
$legacygroupid = optional_param('group', 0, PARAM_INT);
$userid = optional_param('user', 0, PARAM_INT);
$resetopcache = optional_param('resetopcache', 0, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'kanban');

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/kanban:view', $context);

if ($resetopcache && is_siteadmin() && confirm_sesskey()) {
    require_once($CFG->libdir . '/adminlib.php');
    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }
    purge_all_caches();

    $redirectparams = ['id' => $id];
    if (!empty($boardid)) {
        $redirectparams['boardid'] = $boardid;
    }
    if (!empty($requestedgroupid)) {
        $redirectparams['groupid'] = $requestedgroupid;
    }
    if (!empty($legacygroupid)) {
        $redirectparams['group'] = $legacygroupid;
    }
    if (!empty($userid)) {
        $redirectparams['user'] = $userid;
    }
    redirect(new moodle_url('/mod/kanban/view.php', $redirectparams));
}

$kanban = $DB->get_record('kanban', ['id' => $cm->instance], '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/mod/kanban/view.php', ['id' => $id]));
$PAGE->set_title(get_string('pluginname', 'mod_kanban') . ' ' . $kanban->name);
$PAGE->set_heading($kanban->name);

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

echo $OUTPUT->header();

$groupselector = '';
$groupid = 0;
$currentgroupid = 0;
$boardmode = (int)($kanban->boardmode ?? constants::MOD_KANBAN_BOARDMODE_SHARED);
$canaccessotherboards = has_capability('mod/kanban:viewallboards', $context) ||
    has_capability('mod/kanban:editallboards', $context);
$allowedgroups = [];
$boardmanager = new boardmanager($cm->id);
$defaultgroupid = $boardmanager->get_preferred_board_group_id();

$groupmode = groups_get_activity_groupmode($cm, $course);

if (!empty($groupmode)) {
    $currentgroupid = groups_get_activity_group($cm, true);
}

if (!$canaccessotherboards) {
    $groupid = 0;
    $userid = 0;
}

if (empty($requestedgroupid) && !empty($legacygroupid)) {
    $requestedgroupid = $legacygroupid;
}

if (empty($boardid) && !empty($userid) && !empty($kanban->userboards) && ($userid == $USER->id || $canaccessotherboards)) {
    $boardid = $boardmanager->get_or_create_board((int)$userid);
}

if (empty($boardid)) {
    if ($boardmode == constants::MOD_KANBAN_BOARDMODE_GROUP) {
        $groupid = 0;
        $allowedgroups = $boardmanager->get_available_board_groups();

        if ($canaccessotherboards && !empty($requestedgroupid) && !empty($allowedgroups[$requestedgroupid])) {
            $groupid = $requestedgroupid;
        } else if (
            !empty($currentgroupid) &&
            !empty($allowedgroups[$currentgroupid]) &&
            ($canaccessotherboards || groups_is_member((int)$currentgroupid, $USER->id))
        ) {
            $groupid = $currentgroupid;
        } else if ($canaccessotherboards && !empty($defaultgroupid) && !empty($allowedgroups[$defaultgroupid])) {
            $groupid = $defaultgroupid;
        } else if ($canaccessotherboards && !empty($allowedgroups)) {
            $firstgroup = reset($allowedgroups);
            $groupid = $firstgroup->id;
        } else if (!$canaccessotherboards && !empty($allowedgroups)) {
            foreach ($allowedgroups as $allowedgroup) {
                if (groups_is_member((int)$allowedgroup->id, $USER->id)) {
                    $groupid = (int)$allowedgroup->id;
                    break;
                }
            }
        }

        if (!empty($groupid)) {
            $boardid = $boardmanager->get_or_create_board(0, $groupid);
        } else {
            if (!$canaccessotherboards) {
                throw new moodle_exception('nogroupavailable', 'mod_kanban');
            }
            $boardid = $boardmanager->get_or_create_board_for_mode($boardmode, $groupid);
        }
    } else {
        $boardid = $boardmanager->get_or_create_board_for_mode($boardmode, $groupid);
    }
    $boardmanager->load_board($boardid);
    $board = $boardmanager->get_board();
} else {
    $board = $DB->get_record('kanban_board', ['id' => $boardid, 'kanban_instance' => $kanban->id]);
    helper::check_permissions_for_user_or_group($board, $context, $cm, constants::MOD_KANBAN_VIEW);
}

echo $OUTPUT->render_from_template(
    'mod_kanban/container',
    [
        'cmid' => $cm->id,
        'id' => $boardid,
    ]
);

$PAGE->requires->js_call_amd('mod_kanban/main', 'init', ['mod_kanban_render_container-' . $cm->id, $cm->id, $boardid]);

echo $OUTPUT->footer();

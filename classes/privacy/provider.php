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
 * Privacy provider for mod_kanban.
 *
 * @package    mod_kanban
 * @copyright   2023-2024 ISB Bayern
 * @author     Stefan Hanauska
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_kanban\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\helper;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\local\metadata\collection;

/**
 * Privacy provider for mod_kanban.
 *
 * @package    mod_kanban
 * @copyright   2023-2024 ISB Bayern
 * @author     Stefan Hanauska
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\metadata\provider {
    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        foreach ($userlist->get_userids() as $userid) {
            self::delete_user_data_from_context($context, (int)$userid);
        }
    }
    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }

        $params = ['cmid' => $context->instanceid, 'modname' => 'kanban'];
        $queries = [
            "SELECT DISTINCT b.userid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {kanban_board} b ON b.kanban_instance = cm.instance
              WHERE cm.id = :cmid AND b.userid > 0",
            "SELECT DISTINCT ca.createdby AS userid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {kanban_board} b ON b.kanban_instance = cm.instance
               JOIN {kanban_card} ca ON ca.kanban_board = b.id
              WHERE cm.id = :cmid AND ca.createdby > 0",
            "SELECT DISTINCT a.userid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {kanban_board} b ON b.kanban_instance = cm.instance
               JOIN {kanban_card} ca ON ca.kanban_board = b.id
               JOIN {kanban_assignee} a ON a.kanban_card = ca.id
              WHERE cm.id = :cmid",
            "SELECT DISTINCT d.userid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {kanban_board} b ON b.kanban_instance = cm.instance
               JOIN {kanban_card} ca ON ca.kanban_board = b.id
               JOIN {kanban_discussion_comment} d ON d.kanban_card = ca.id
              WHERE cm.id = :cmid",
            "SELECT DISTINCT h.userid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {kanban_board} b ON b.kanban_instance = cm.instance
               JOIN {kanban_history} h ON h.kanban_board = b.id
              WHERE cm.id = :cmid AND h.userid > 0",
            "SELECT DISTINCT h.affected_userid AS userid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {kanban_board} b ON b.kanban_instance = cm.instance
               JOIN {kanban_history} h ON h.kanban_board = b.id
              WHERE cm.id = :cmid AND h.affected_userid > 0",
            "SELECT DISTINCT e.userid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {event} e ON e.instance = cm.instance AND e.modulename = :modname
              WHERE cm.id = :cmid AND e.userid > 0",
        ];

        foreach ($queries as $sql) {
            $userlist->add_from_sql('userid', $sql, $params);
        }
    }
    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return  contextlist   $contextlist  The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $params = [
            'modname' => 'kanban',
            'contextlevel' => CONTEXT_MODULE,
            'userid' => $userid,
        ];
        $base = "  FROM {context} c
                    JOIN {course_modules} cm ON cm.id = c.instanceid AND c.contextlevel = :contextlevel
                    JOIN {modules} m ON m.id = cm.module AND m.name = :modname";
        $queries = [
            "SELECT c.id {$base}
               JOIN {kanban_board} b ON b.kanban_instance = cm.instance
               JOIN {kanban_card} ca ON ca.kanban_board = b.id
               JOIN {kanban_assignee} a ON a.kanban_card = ca.id
              WHERE a.userid = :userid",
            "SELECT c.id {$base}
               JOIN {kanban_board} b ON b.kanban_instance = cm.instance
              WHERE b.userid = :userid",
            "SELECT c.id {$base}
               JOIN {kanban_board} b ON b.kanban_instance = cm.instance
               JOIN {kanban_card} ca ON ca.kanban_board = b.id
              WHERE ca.createdby = :userid",
            "SELECT c.id {$base}
               JOIN {kanban_board} b ON b.kanban_instance = cm.instance
               JOIN {kanban_card} ca ON ca.kanban_board = b.id
               JOIN {kanban_discussion_comment} d ON d.kanban_card = ca.id
              WHERE d.userid = :userid",
            "SELECT c.id {$base}
               JOIN {kanban_board} b ON b.kanban_instance = cm.instance
               JOIN {kanban_history} h ON h.kanban_board = b.id
              WHERE h.userid = :userid",
            "SELECT c.id {$base}
               JOIN {kanban_board} b ON b.kanban_instance = cm.instance
               JOIN {kanban_history} h ON h.kanban_board = b.id
              WHERE h.affected_userid = :userid",
            "SELECT c.id {$base}
               JOIN {event} e ON e.instance = cm.instance AND e.modulename = :modname
              WHERE e.userid = :userid",
        ];

        foreach ($queries as $sql) {
            $contextlist->add_from_sql($sql, $params);
        }
        return $contextlist;
    }
    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!$contextlist->count()) {
            return;
        }

        $user = $contextlist->get_user();
        foreach ($contextlist->get_contextids() as $contextid) {
            $context = \context::instance_by_id($contextid);
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('kanban', $context->instanceid);
            if (!$cm) {
                continue;
            }

            writer::with_context($context)->export_data([], helper::get_context_data($context, $user));
            helper::export_context_files($context, $user);
            $params = ['instance' => $cm->instance, 'userid' => $user->id];

            $sql = "SELECT ca.id, ca.title, ca.description, ca.descriptionformat, ca.options,
                           ca.duedate, ca.reminderdate, ca.completed, ca.timecreated, ca.timemodified,
                           co.title AS columntitle, b.groupid
                      FROM {kanban_card} ca
                      JOIN {kanban_column} co ON co.id = ca.kanban_column
                      JOIN {kanban_board} b ON b.id = ca.kanban_board
                     WHERE b.kanban_instance = :instance AND ca.createdby = :userid
                  ORDER BY ca.id";
            self::export_records($context, 'created_cards', $DB->get_records_sql($sql, $params));

            $sql = "SELECT ca.id, ca.title, co.title AS columntitle, b.groupid, ca.timemodified
                      FROM {kanban_assignee} a
                      JOIN {kanban_card} ca ON ca.id = a.kanban_card
                      JOIN {kanban_column} co ON co.id = ca.kanban_column
                      JOIN {kanban_board} b ON b.id = ca.kanban_board
                     WHERE b.kanban_instance = :instance AND a.userid = :userid
                  ORDER BY ca.id";
            self::export_records($context, 'assigned_cards', $DB->get_records_sql($sql, $params));

            $sql = "SELECT d.id, d.content, d.timecreated, ca.title AS cardtitle, co.title AS columntitle
                      FROM {kanban_discussion_comment} d
                      JOIN {kanban_card} ca ON ca.id = d.kanban_card
                      JOIN {kanban_column} co ON co.id = ca.kanban_column
                      JOIN {kanban_board} b ON b.id = ca.kanban_board
                     WHERE b.kanban_instance = :instance AND d.userid = :userid
                  ORDER BY d.id";
            self::export_records($context, 'discussion_comments', $DB->get_records_sql($sql, $params));

            $sql = "SELECT h.id, h.action, h.parameters, h.affected_userid, h.timestamp,
                           ca.title AS cardtitle, co.title AS columntitle
                      FROM {kanban_history} h
                      JOIN {kanban_board} b ON b.id = h.kanban_board
                 LEFT JOIN {kanban_card} ca ON ca.id = h.kanban_card
                 LEFT JOIN {kanban_column} co ON co.id = h.kanban_column
                     WHERE b.kanban_instance = :instance
                       AND (h.userid = :userid OR h.affected_userid = :affecteduserid)
                  ORDER BY h.id";
            $historyparams = $params + ['affecteduserid' => $user->id];
            self::export_records($context, 'history', $DB->get_records_sql($sql, $historyparams));

            $sql = "SELECT b.id, b.groupid, b.options, b.timecreated, b.timemodified
                      FROM {kanban_board} b
                     WHERE b.kanban_instance = :instance AND b.userid = :userid
                  ORDER BY b.id";
            self::export_records($context, 'personal_boards', $DB->get_records_sql($sql, $params));
        }
    }

    /**
     * Export records under a stable subpath.
     *
     * @param \context_module $context Module context.
     * @param string $path Export path.
     * @param array $records Records to export.
     */
    private static function export_records(\context_module $context, string $path, array $records): void {
        if ($records) {
            writer::with_context($context)->export_data([$path], (object)['items' => array_values($records)]);
        }
    }
    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('kanban', $context->instanceid);
        if (!$cm) {
            return;
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('event', ['modulename' => 'kanban', 'instance' => $cm->instance]);
        $boardids = $DB->get_fieldset_select(
            'kanban_board',
            'id',
            'kanban_instance = :instance',
            ['instance' => $cm->instance]
        );
        if (!$boardids) {
            $transaction->allow_commit();
            return;
        }

        [$boardsql, $boardparams] = $DB->get_in_or_equal($boardids, SQL_PARAMS_NAMED, 'board');
        $cardids = $DB->get_fieldset_select('kanban_card', 'id', 'kanban_board ' . $boardsql, $boardparams);
        self::delete_card_dependants($context, $cardids);
        $DB->delete_records_select('kanban_history', 'kanban_board ' . $boardsql, $boardparams);
        $DB->delete_records_select('kanban_card', 'kanban_board ' . $boardsql, $boardparams);

        $nontemplateids = $DB->get_fieldset_select(
            'kanban_board',
            'id',
            'kanban_instance = :instance AND template = 0',
            ['instance' => $cm->instance]
        );
        if ($nontemplateids) {
            [$nontemplatesql, $nontemplateparams] = $DB->get_in_or_equal(
                $nontemplateids,
                SQL_PARAMS_NAMED,
                'nontemplate'
            );
            $DB->delete_records_select('kanban_column', 'kanban_board ' . $nontemplatesql, $nontemplateparams);
            $DB->delete_records_select('kanban_board', 'id ' . $nontemplatesql, $nontemplateparams);
        }

        $templateids = $DB->get_fieldset_select(
            'kanban_board',
            'id',
            'kanban_instance = :instance AND template = 1',
            ['instance' => $cm->instance]
        );
        if ($templateids) {
            [$templatesql, $templateparams] = $DB->get_in_or_equal($templateids, SQL_PARAMS_NAMED, 'template');
            $DB->set_field_select('kanban_column', 'sequence', '', 'kanban_board ' . $templatesql, $templateparams);
        }
        $transaction->allow_commit();
    }

    /**
     * Delete user data for approved contexts.
     *
     * @param approved_contextlist $contextlist Approved contexts.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contextids() as $contextid) {
            $context = \context::instance_by_id($contextid);
            if ($context instanceof \context_module) {
                self::delete_user_data_from_context($context, $userid);
            }
        }
    }

    /**
     * Delete one user's data from one Kanban context.
     *
     * Shared cards are retained and anonymised. A personal board belongs to
     * the user and is removed together with its descendants.
     *
     * @param \context_module $context Module context.
     * @param int $userid User id.
     */
    private static function delete_user_data_from_context(\context_module $context, int $userid): void {
        global $DB;

        $cm = get_coursemodule_from_id('kanban', $context->instanceid);
        if (!$cm) {
            return;
        }

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('event', [
            'modulename' => 'kanban',
            'instance' => $cm->instance,
            'userid' => $userid,
        ]);
        $boardids = $DB->get_fieldset_select(
            'kanban_board',
            'id',
            'kanban_instance = :instance',
            ['instance' => $cm->instance]
        );
        if (!$boardids) {
            $transaction->allow_commit();
            return;
        }

        [$boardsql, $boardparams] = $DB->get_in_or_equal($boardids, SQL_PARAMS_NAMED, 'board');
        $params = $boardparams + ['userid' => $userid];
        $DB->delete_records_select('kanban_history', 'userid = :userid AND kanban_board ' . $boardsql, $params);
        $DB->set_field_select(
            'kanban_history',
            'affected_userid',
            0,
            'affected_userid = :userid AND kanban_board ' . $boardsql,
            $params
        );
        $DB->set_field_select(
            'kanban_card',
            'createdby',
            0,
            'createdby = :userid AND kanban_board ' . $boardsql,
            $params
        );

        $cardids = $DB->get_fieldset_select('kanban_card', 'id', 'kanban_board ' . $boardsql, $boardparams);
        if ($cardids) {
            [$cardsql, $cardparams] = $DB->get_in_or_equal($cardids, SQL_PARAMS_NAMED, 'card');
            $cardparams['userid'] = $userid;
            $DB->delete_records_select(
                'kanban_assignee',
                'userid = :userid AND kanban_card ' . $cardsql,
                $cardparams
            );
            $DB->delete_records_select(
                'kanban_discussion_comment',
                'userid = :userid AND kanban_card ' . $cardsql,
                $cardparams
            );
        }

        $personalboardids = $DB->get_fieldset_select(
            'kanban_board',
            'id',
            'kanban_instance = :instance AND userid = :userid',
            ['instance' => $cm->instance, 'userid' => $userid]
        );
        if ($personalboardids) {
            [$personalsql, $personalparams] = $DB->get_in_or_equal(
                $personalboardids,
                SQL_PARAMS_NAMED,
                'personal'
            );
            $personalcardids = $DB->get_fieldset_select(
                'kanban_card',
                'id',
                'kanban_board ' . $personalsql,
                $personalparams
            );
            self::delete_card_dependants($context, $personalcardids);
            $DB->delete_records_select('kanban_history', 'kanban_board ' . $personalsql, $personalparams);
            $DB->delete_records_select('kanban_card', 'kanban_board ' . $personalsql, $personalparams);
            $DB->delete_records_select('kanban_column', 'kanban_board ' . $personalsql, $personalparams);
            $DB->delete_records_select('kanban_board', 'id ' . $personalsql, $personalparams);
        }
        $transaction->allow_commit();
    }

    /**
     * Delete card relations and attachments.
     *
     * @param \context_module $context Module context.
     * @param array $cardids Card ids.
     */
    private static function delete_card_dependants(\context_module $context, array $cardids): void {
        global $DB;

        if (!$cardids) {
            return;
        }
        [$cardsql, $cardparams] = $DB->get_in_or_equal($cardids, SQL_PARAMS_NAMED, 'card');
        $DB->delete_records_select('kanban_assignee', 'kanban_card ' . $cardsql, $cardparams);
        $DB->delete_records_select('kanban_discussion_comment', 'kanban_card ' . $cardsql, $cardparams);

        $fs = get_file_storage();
        foreach ($cardids as $cardid) {
            $fs->delete_area_files($context->id, 'mod_kanban', 'attachments', $cardid);
        }
    }
    /**
     * Returns meta data about this system.
     *
     * @param   collection     $collection The initialised collection to add items to.
     * @return  collection     A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('kanban_board', [
            'userid' => 'privacy:metadata:userid',
            'groupid' => 'privacy:metadata:groupid',
            'options' => 'privacy:metadata:options',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:kanban_board');

        $collection->add_database_table('kanban_column', [
            'title' => 'privacy:metadata:title',
            'options' => 'privacy:metadata:options',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:kanban_column');

        $collection->add_database_table('kanban_card', [
            'title' => 'privacy:metadata:title',
            'description' => 'privacy:metadata:description',
            'options' => 'privacy:metadata:options',
            'duedate' => 'privacy:metadata:duedate',
            'reminderdate' => 'privacy:metadata:reminderdate',
            'completed' => 'privacy:metadata:completed',
            'timecreated' => 'privacy:metadata:timecreated',
            'timemodified' => 'privacy:metadata:timemodified',
            'createdby' => 'privacy:metadata:createdby',
        ], 'privacy:metadata:kanban_card');

        $collection->add_database_table('kanban_assignee', [
            'userid' => 'privacy:metadata:userid',
            'kanban_card' => 'privacy:metadata:kanban_card',
        ], 'privacy:metadata:kanban_assignee');

        $collection->add_database_table('kanban_discussion_comment', [
            'userid' => 'privacy:metadata:userid',
            'kanban_card' => 'privacy:metadata:kanban_card',
            'content' => 'privacy:metadata:content',
            'timecreated' => 'privacy:metadata:timecreated',
        ], 'privacy:metadata:kanban_discussion_comment');

        $collection->add_database_table('kanban_history', [
            'userid' => 'privacy:metadata:userid',
            'kanban_board' => 'privacy:metadata:kanban_board',
            'kanban_column' => 'privacy:metadata:kanban_column',
            'kanban_card' => 'privacy:metadata:kanban_card',
            'parameters' => 'privacy:metadata:parameters',
            'action' => 'privacy:metadata:action',
            'affected_userid' => 'privacy:metadata:affected_userid',
            'timestamp' => 'privacy:metadata:timestamp',
        ], 'privacy:metadata:kanban_history');

        return $collection;
    }
}

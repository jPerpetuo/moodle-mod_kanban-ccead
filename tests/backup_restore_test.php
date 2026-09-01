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
 * Backup and restore tests for mod_kanban.
 *
 * @package     mod_kanban
 * @category    test
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_kanban;

use advanced_testcase;
use backup;
use backup_controller;
use backup_setting;
use restore_controller;
use restore_dbops;
use stdClass;

/**
 * Tests the structural import behaviour without user data.
 *
 * @package     mod_kanban
 * @covers      \backup_kanban_activity_structure_step
 * @covers      \restore_kanban_activity_structure_step
 */
final class backup_restore_test extends advanced_testcase {
    /**
     * Loads Moodle backup and restore APIs.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void {
        global $CFG;

        parent::setUpBeforeClass();

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
    }

    /**
     * A no-user-data import keeps columns and excludes cards.
     *
     * @return void
     */
    public function test_backup_restore_without_user_data_keeps_structure(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $kanban = $this->getDataGenerator()->create_module('kanban', ['course' => $course]);
        $board = $DB->get_record('kanban_board', [
            'kanban_instance' => $kanban->id,
            'userid' => 0,
            'groupid' => 0,
            'template' => 0,
        ], '*', MUST_EXIST);
        $manager = new boardmanager($kanban->cmid, $board->id);
        // The template is deliberately stale: import must use the active board structure.
        $manager->create_template();
        $manager->add_column(0, ['title' => 'Revisão', 'options' => '{"color":"#f7d7d7"}']);

        $columnid = $DB->get_field('kanban_column', 'id', [
            'kanban_board' => $board->id,
            'title' => 'Revisão',
        ], MUST_EXIST);
        $manager->add_card($columnid, 0, ['title' => 'Conteúdo do curso de origem']);

        $newcourseid = $this->backup_and_restore($course, false);
        $restoredkanban = $DB->get_record('kanban', ['course' => $newcourseid], '*', MUST_EXIST);
        $template = $DB->get_record('kanban_board', [
            'kanban_instance' => $restoredkanban->id,
            'template' => 1,
        ], '*', MUST_EXIST);

        $this->assertSame(0, $DB->count_records('kanban_card', ['kanban_board' => $template->id]));
        $this->assertSame(4, $DB->count_records('kanban_column', ['kanban_board' => $template->id]));
        $templatecolumns = $DB->get_records('kanban_column', ['kanban_board' => $template->id]);
        foreach ($templatecolumns as $templatecolumn) {
            $this->assertSame('', $templatecolumn->sequence);
        }
        $templatecolumn = $DB->get_record('kanban_column', [
            'kanban_board' => $template->id,
            'title' => 'Revisão',
        ], '*', MUST_EXIST);
        $this->assertSame('{"color":"#f7d7d7"}', $templatecolumn->options);

        $restoredcm = get_coursemodule_from_instance('kanban', $restoredkanban->id, $newcourseid, false, MUST_EXIST);
        $restoredmanager = new boardmanager($restoredcm->id);
        $restoredboardid = $restoredmanager->create_board();

        $this->assertSame(0, $DB->count_records('kanban_card', ['kanban_board' => $restoredboardid]));
        $this->assertSame(4, $DB->count_records('kanban_column', ['kanban_board' => $restoredboardid]));
        $restoredcolumn = $DB->get_record('kanban_column', [
            'kanban_board' => $restoredboardid,
            'title' => 'Revisão',
        ], '*', MUST_EXIST);
        $this->assertSame('{"color":"#f7d7d7"}', $restoredcolumn->options);
    }

    /**
     * Backup and restore a course with the requested user-data setting.
     *
     * @param stdClass $sourcecourse Source course.
     * @param bool $userdata Whether user data is included.
     * @return int Destination course ID.
     */
    private function backup_and_restore(stdClass $sourcecourse, bool $userdata): int {
        global $CFG, $USER;

        $CFG->backup_file_logger_level = backup::LOG_NONE;
        $backup = new backup_controller(
            backup::TYPE_1COURSE,
            $sourcecourse->id,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_IMPORT,
            $USER->id
        );
        $backup->get_plan()->get_setting('users')->set_status(backup_setting::NOT_LOCKED);
        $backup->get_plan()->get_setting('users')->set_value($userdata);
        $backupid = $backup->get_backupid();
        $backup->execute_plan();
        $backup->destroy();

        $destinationcourseid = restore_dbops::create_new_course(
            $sourcecourse->fullname,
            $sourcecourse->shortname . '_restored',
            $sourcecourse->category
        );
        $restore = new restore_controller(
            $backupid,
            $destinationcourseid,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $USER->id,
            backup::TARGET_NEW_COURSE
        );
        $restore->get_plan()->get_setting('users')->set_status(backup_setting::NOT_LOCKED);
        $restore->get_plan()->get_setting('users')->set_value($userdata);
        $this->assertTrue($restore->execute_precheck());
        $restore->execute_plan();
        $restore->destroy();

        return $destinationcourseid;
    }
}

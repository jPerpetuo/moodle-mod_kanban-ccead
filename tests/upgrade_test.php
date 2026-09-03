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

namespace mod_kanban;

/**
 * Tests for Kanban database upgrades.
 *
 * @package     mod_kanban
 * @copyright   2026 CCEAD PUC-Rio
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      ::xmldb_kanban_upgrade
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * Load the plugin upgrade steps.
     *
     * @return void
     */
    public function setUp(): void {
        global $CFG;

        parent::setUp();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/mod/kanban/db/upgrade.php');
    }

    /**
     * Upgrade preserves an existing activity while adding the group board fields.
     *
     * @return void
     */
    public function test_upgrade_adds_group_board_fields_without_losing_activity_data(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();

        $course = $this->getDataGenerator()->create_course();
        $kanban = $this->getDataGenerator()->create_module('kanban', [
            'course' => $course,
            'name' => 'Legacy group board activity',
            'history' => 1,
        ]);
        $dbman = $DB->get_manager();
        $table = new \xmldb_table('kanban');

        $boardgroups = new \xmldb_field('boardgroups', XMLDB_TYPE_TEXT, null, null, null, null, null, 'boardgroupid');
        $boardgroupid = new \xmldb_field('boardgroupid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'boardmode');
        $dbman->drop_field($table, $boardgroups);
        $dbman->drop_field($table, $boardgroupid);

        \xmldb_kanban_upgrade(2026050701);

        $this->assertTrue($dbman->field_exists($table, $boardgroupid));
        $this->assertTrue($dbman->field_exists($table, $boardgroups));

        $upgraded = $DB->get_record('kanban', ['id' => $kanban->id], '*', MUST_EXIST);
        $this->assertEquals('Legacy group board activity', $upgraded->name);
        $this->assertEquals(1, (int) $upgraded->history);
        $this->assertNull($upgraded->boardgroupid);
        $this->assertNull($upgraded->boardgroups);
    }

    /**
     * Upgrade assigns sequential card numbers to cards created before numbering was available.
     *
     * @return void
     */
    public function test_upgrade_assigns_numbers_to_legacy_cards(): void {
        global $DB;

        $this->resetAfterTest();
        $this->preventResetByRollback();

        $course = $this->getDataGenerator()->create_course();
        $kanban = $this->getDataGenerator()->create_module('kanban', ['course' => $course]);
        $boardmanager = new boardmanager($kanban->cmid);
        $boardid = $boardmanager->create_board();
        $boardmanager->load_board($boardid);
        $columnid = $DB->get_field('kanban_column', 'id', ['kanban_board' => $boardid], IGNORE_MULTIPLE);
        $firstcardid = $boardmanager->add_card($columnid, 0, ['title' => 'First legacy card']);
        $secondcardid = $boardmanager->add_card($columnid, $firstcardid, ['title' => 'Second legacy card']);
        $DB->set_field('kanban_card', 'number', 0, ['id' => $firstcardid]);
        $DB->set_field('kanban_card', 'number', 0, ['id' => $secondcardid]);

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('kanban_card');
        $number = new \xmldb_field('number', XMLDB_TYPE_INTEGER, '10', null, null, null, '0', 'timemodified');
        $dbman->drop_field($table, $number);

        \xmldb_kanban_upgrade(2024121602);

        $this->assertTrue($dbman->field_exists($table, $number));
        $firstcard = $DB->get_record('kanban_card', ['id' => $firstcardid], 'id, number', MUST_EXIST);
        $secondcard = $DB->get_record('kanban_card', ['id' => $secondcardid], 'id, number', MUST_EXIST);
        $this->assertEquals(1, (int) $firstcard->number);
        $this->assertEquals(2, (int) $secondcard->number);
    }
}

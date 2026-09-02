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
 * Tests for Kanban activity form validation.
 *
 * @package     mod_kanban
 * @copyright   2026 CCEAD PUC-Rio
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      ::mod_kanban_mod_form
 */
final class mod_form_test extends \advanced_testcase {
    /**
     * Test setup.
     *
     * @return void
     */
    public function setUp(): void {
        global $CFG;

        parent::setUp();
        require_once($CFG->dirroot . '/mod/kanban/mod_form.php');
    }

    /**
     * Group mode is rejected when the course has no groups.
     *
     * @return void
     */
    public function test_group_mode_requires_course_groups(): void {
        global $PAGE;

        $this->resetAfterTest();
        $_POST = [];
        $course = $this->getDataGenerator()->create_course();
        $kanban = $this->getDataGenerator()->create_module('kanban', ['course' => $course]);
        $cm = get_coursemodule_from_id('kanban', $kanban->cmid, 0, false, MUST_EXIST);
        $PAGE->set_course($course);

        $data = $this->get_form_data($course->id, $kanban->id, $cm->id);
        $form = new \mod_kanban_mod_form($data, 0, $cm, $course);
        $errors = $form->validation((array) $data, []);

        $this->assertArrayHasKey('boardmode', $errors);
        $this->assertEquals(get_string('boardgroupsnogroupsgroupmodeerror', 'kanban'), $errors['boardmode']);
    }

    /**
     * Group mode is rejected when no board group is selected.
     *
     * @return void
     */
    public function test_group_mode_requires_selected_groups(): void {
        global $PAGE;

        $this->resetAfterTest();
        $_POST = [];
        $course = $this->getDataGenerator()->create_course();
        groups_create_group((object) ['courseid' => $course->id, 'name' => 'Test group']);
        $kanban = $this->getDataGenerator()->create_module('kanban', ['course' => $course]);
        $cm = get_coursemodule_from_id('kanban', $kanban->cmid, 0, false, MUST_EXIST);
        $PAGE->set_course($course);

        $data = $this->get_form_data($course->id, $kanban->id, $cm->id);
        $_POST['selectedboardgroupscsv'] = '';
        $_POST['selectedboardgroups'] = [];
        $form = new \mod_kanban_mod_form($data, 0, $cm, $course);
        $errors = $form->validation((array) $data, []);

        $this->assertArrayHasKey('boardmode', $errors);
        $this->assertEquals(get_string('boardgroupsrequired', 'kanban'), $errors['boardmode']);
    }

    /**
     * Group mode accepts a selected board group.
     *
     * @return void
     */
    public function test_group_mode_accepts_selected_group(): void {
        global $PAGE;

        $this->resetAfterTest();
        $_POST = [];
        $course = $this->getDataGenerator()->create_course();
        $groupid = groups_create_group((object) ['courseid' => $course->id, 'name' => 'Test group']);
        $kanban = $this->getDataGenerator()->create_module('kanban', ['course' => $course]);
        $cm = get_coursemodule_from_id('kanban', $kanban->cmid, 0, false, MUST_EXIST);
        $PAGE->set_course($course);

        $data = $this->get_form_data($course->id, $kanban->id, $cm->id);
        $_POST['selectedboardgroupscsv'] = (string) $groupid;
        $_POST['selectedboardgroups'] = [$groupid];
        $form = new \mod_kanban_mod_form($data, 0, $cm, $course);
        $errors = $form->validation((array) $data, []);

        $this->assertArrayNotHasKey('boardmode', $errors);
    }

    /**
     * Return the minimum form data required by the validation under test.
     *
     * @param int $courseid Course identifier.
     * @param int $instance Activity instance identifier.
     * @param int $coursemodule Course module identifier.
     * @return object
     */
    private function get_form_data(int $courseid, int $instance, int $coursemodule): object {
        return (object) [
            'course' => $courseid,
            'modulename' => 'kanban',
            'instance' => $instance,
            'coursemodule' => $coursemodule,
            'cmidnumber' => '',
            'availabilityconditionsjson' => '{"op":"&","c":[],"showc":[]}',
            'name' => 'Test Kanban',
            'boardmode' => constants::MOD_KANBAN_BOARDMODE_GROUP,
            'boardgroups' => '',
            'selectedboardgroupscsv' => '',
            'selectedboardgroups' => [],
        ];
    }
}

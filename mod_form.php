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

use mod_kanban\constants;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Editing form for mod_kanban
 *
 * @package     mod_kanban
 * @copyright   2023-2024 ISB Bayern
 * @author      Stefan Hanauska <stefan.hanauska@csg-in.de>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_kanban_mod_form extends moodleform_mod {
    /**
     * Defines the editing form for mod_kanban
     *
     * @return void
     */
    public function definition(): void {
        global $COURSE, $PAGE;
        $mform = $this->_form;

        $mform->addElement('header', 'generalhdr', get_string('general'));

        $mform->addElement('text', 'name', get_string('name', 'kanban'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addHelpButton('name', 'name', 'kanban');

        $this->standard_intro_elements(get_string('description'));

        $boardmodes = [
            constants::MOD_KANBAN_BOARDMODE_SHARED => get_string('boardmodeshared', 'kanban'),
            constants::MOD_KANBAN_BOARDMODE_GROUP => get_string('boardmodegroup', 'kanban'),
        ];
        $mform->addElement('select', 'boardmode', get_string('boardmode', 'kanban'), $boardmodes);
        $mform->setDefault('boardmode', constants::MOD_KANBAN_BOARDMODE_SHARED);
        $mform->addHelpButton('boardmode', 'boardmode', 'kanban');

        $courseid = !empty($this->current->course) ? $this->current->course : ($COURSE->id ?? 0);
        $groups = [];
        if (!empty($courseid)) {
            $groups = groups_get_all_groups($courseid, 0, 0, 'g.id, g.name');
        }

        $selectedgroupids = $this->get_initial_board_group_ids($groups);
        $availablegroups = array_filter($groups, function($group) use ($selectedgroupids) {
            return !in_array((int)$group->id, $selectedgroupids, true);
        });
        $selectedgroups = array_filter($groups, function($group) use ($selectedgroupids) {
            return in_array((int)$group->id, $selectedgroupids, true);
        });
        $serializedselectedgroups = implode(',', $selectedgroupids);
        $primarygroupid = !empty($selectedgroupids) ? reset($selectedgroupids) : 0;

        $mform->addElement('header', 'groups', get_string('groups', 'group'));
        $mform->addElement('html', '<div id="kanban-boardgroups-selector">');
        if (!empty($groups)) {
            $mform->addElement('html', '
                <div class="fcontainer clearfix">
                    <label for="availableboardgroups" class="fitemtitle">' .
                        get_string('boardgroupsdescription', 'kanban') . '</label>
                    <div class="fitem fitem_fselect">
                        <div class="felement fselect">
                            <div class="tablecontainer">
                                <table class="table-reboot" style="width: 100%; max-width: 64rem;">
                                    <tr class="row">
                                        <th class="col-lg-5">' . get_string('boardgroupsavailable', 'kanban') . '</th>
                                        <th class="col-lg-2"></th>
                                        <th class="col-lg-5">' . get_string('boardgroupsselected', 'kanban') . '</th>
                                    </tr>
                                    <tr class="row">
                                        <td style="vertical-align: top" class="col-5">
                                            <select class="col-12" id="availableboardgroups" multiple size="10" style="width: 100%; min-width: 20rem;">');
            foreach ($availablegroups as $group) {
                $mform->addElement('html', '<option value="' . (int)$group->id . '">' .
                    format_string($group->name) . '</option>');
            }
            $mform->addElement('html', '
                                            </select>
                                        </td>
                                        <td class="col-2">
                                            <button id="addBoardGroupButton" type="button" class="btn btn-secondary mt-1">' .
                                                get_string('boardgroupsadd', 'kanban') . '</button>
                                            <div>
                                                <button id="removeBoardGroupButton" type="button" class="btn btn-secondary mt-1">' .
                                                    get_string('boardgroupsremove', 'kanban') . '</button>
                                            </div>
                                        </td>
                                        <td style="vertical-align: top" class="col-5">
                                            <select class="col-12" id="id_selectedBoardGroups" multiple size="10" style="width: 100%; min-width: 20rem;">');
            foreach ($selectedgroups as $group) {
                $mform->addElement('html', '<option value="' . (int)$group->id . '">' .
                    format_string($group->name) . '</option>');
            }
            $mform->addElement('html', '
                                            </select>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>');
        } else {
            $mform->addElement('html', '<div class="alert alert-info">' . get_string('boardgroupsnogroups', 'kanban') . '</div>');
        }
        $mform->addElement('hidden', 'boardgroups', $serializedselectedgroups);
        $mform->setType('boardgroups', PARAM_SEQUENCE);
        $mform->addElement('hidden', 'boardgroupid', $primarygroupid);
        $mform->setType('boardgroupid', PARAM_INT);
        $mform->addElement('html', '</div>');
        $PAGE->requires->js_call_amd('mod_kanban/boardgroupsetting', 'init', [[
            'formid' => $mform->getAttribute('id'),
            'boardmodefieldid' => 'id_boardmode',
            'containerid' => 'kanban-boardgroups-selector',
            'groupmodevalue' => constants::MOD_KANBAN_BOARDMODE_GROUP,
        ]]);

        $userboards = [
            constants::MOD_KANBAN_NOUSERBOARDS => get_string('nouserboards', 'kanban'),
            constants::MOD_KANBAN_USERBOARDS_ENABLED => get_string('userboardsenabled', 'kanban'),
            constants::MOD_KANBAN_USERBOARDS_ONLY => get_string('userboardsonly', 'kanban'),
        ];
        $mform->addElement('select', 'userboards', get_string('userboards', 'kanban'), $userboards);
        $mform->addHelpButton('userboards', 'userboards', 'mod_kanban');

        if (!empty(get_config('mod_kanban', 'enablehistory'))) {
            $mform->addElement('advcheckbox', 'history', get_string('enablehistory', 'mod_kanban'));
            $mform->addHelpButton('history', 'enablehistory', 'mod_kanban');
        }

        $mform->addElement('advcheckbox', 'usenumbers', get_string('usenumbers', 'mod_kanban'));
        $mform->addHelpButton('usenumbers', 'usenumbers', 'mod_kanban');

        $mform->addElement('advcheckbox', 'linknumbers', get_string('linknumbers', 'mod_kanban'));
        $mform->addHelpButton('linknumbers', 'linknumbers', 'mod_kanban');
        $mform->hideIf('linknumbers', 'usenumbers', 'notchecked');
        $mform->setDefault('linknumbers', 1);
        $mform->setType('linknumbers', PARAM_INT);

        $this->standard_coursemodule_elements();

        $this->add_action_buttons(true, null, null);
    }

    /**
     * Determine the initial group ids shown as selected in the board selector.
     *
     * Empty stored configuration means "all groups".
     *
     * @param array $groups Available course groups.
     * @return array<int>
     */
    private function get_initial_board_group_ids(array $groups): array {
        $groupids = [];
        if (!empty($this->current->boardgroups)) {
            $groupids = preg_split('/[;,]/', (string)$this->current->boardgroups, -1, PREG_SPLIT_NO_EMPTY);
            $groupids = array_map('intval', $groupids);
        }
        if (empty($groupids) && !empty($groups)) {
            $groupids = array_map(function($group) {
                return (int)$group->id;
            }, $groups);
        }
        $groupids = array_filter($groupids, function(int $groupid) use ($groups): bool {
            return !empty($groups[$groupid]);
        });
        return array_values(array_unique($groupids));
    }

    /**
     * Validate group-board selector settings.
     *
     * @param array $data Form data.
     * @param array $files Uploaded files.
     * @return array
     */
    public function validation($data, $files): array {
        global $COURSE;

        $errors = parent::validation($data, $files);

        if ((int)($data['boardmode'] ?? 0) !== constants::MOD_KANBAN_BOARDMODE_GROUP) {
            return $errors;
        }

        $courseid = !empty($this->current->course) ? $this->current->course : ($COURSE->id ?? 0);
        $groups = [];
        if (!empty($courseid)) {
            $groups = groups_get_all_groups($courseid, 0, 0, 'g.id, g.name');
        }

        if (!empty($groups) && empty(trim((string)($data['boardgroups'] ?? '')))) {
            $errors['boardmode'] = get_string('boardgroupsrequired', 'kanban');
        }

        return $errors;
    }

    /**
     * Returns whether the custom completion rules are enabled.
     *
     * @param array $data form data
     * @return bool
     */
    public function completion_rule_enabled($data): bool {
        return (
            !empty($data['completioncreate' . $this->get_suffix()]) ||
            !empty($data['completioncomplete' . $this->get_suffix()])
        );
    }

    /**
     * Adds the custom completion rules for mod_kanban
     *
     * @return array
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;

        $completioncreate = 'completioncreate' . $this->get_suffix();
        $completioncomplete = 'completioncomplete' . $this->get_suffix();

        $mform->addElement(
            'text',
            $completioncreate,
            get_string('completioncreate', 'kanban'),
            ['size' => 3]
        );
        $mform->setType($completioncreate, PARAM_INT);

        $mform->addElement(
            'text',
            $completioncomplete,
            get_string('completioncomplete', 'kanban'),
            ['size' => 3]
        );
        $mform->setType($completioncomplete, PARAM_INT);

        return ([$completioncreate, $completioncomplete]);
    }

    /**
     * Get the suffix to be added to the completion elements when creating them.
     * This acts as a spare for compatibility with Moodle 4.1 and 4.2.
     *
     * @return string The suffix
     */
    public function get_suffix(): string {
        if (method_exists(get_parent_class($this), 'get_suffix')) {
            return parent::get_suffix();
        }
        return '';
    }
}

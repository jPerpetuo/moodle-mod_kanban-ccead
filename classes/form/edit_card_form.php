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

namespace mod_kanban\form;

use context;
use context_module;
use core_form\dynamic_form;
use core_user;
use mod_kanban\boardmanager;
use mod_kanban\helper;
use mod_kanban\constants;
use moodle_url;

/**
 * From for editing a card.
 *
 * @package    mod_kanban
 * @copyright  2023-2024 ISB Bayern
 * @author     Stefan Hanauska
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_card_form extends dynamic_form {
    /**
     * Define the form
     */
    public function definition() {
        global $DB;
        $mform =& $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'boardid');
        $mform->setType('boardid', PARAM_INT);

        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('text', 'title', get_string('cardtitle', 'kanban'), ['size' => '50']);
        $mform->setType('title', PARAM_TEXT);

        $userid = $this->optional_param('userid', 0, PARAM_INT);
        $groupid = $this->optional_param('groupid', 0, PARAM_INT);
        $cardid = $this->optional_param('id', 0, PARAM_INT);

        $context = $this->get_context_for_dynamic_submission();
        if (has_capability('mod/kanban:assignothers', $context)) {
            $userlist = get_enrolled_users($context, '', $groupid);

            $users = [];
            foreach ($userlist as $user) {
                if (!empty($userid) && $userid != $user->id) {
                    continue;
                }
                $users[$user->id] = fullname($user);
            }
            if (!empty($cardid)) {
                $assignedusers = $DB->get_fieldset_select(
                    'kanban_assignee',
                    'userid',
                    'kanban_card = :cardid',
                    ['cardid' => $cardid]
                );
                foreach ($assignedusers as $assigneduserid) {
                    $assigneduserid = (int) $assigneduserid;
                    if (isset($users[$assigneduserid])) {
                        continue;
                    }
                    if ($user = core_user::get_user($assigneduserid, '*', MUST_EXIST, false)) {
                        $users[$assigneduserid] = fullname($user);
                    }
                }
            }
            $mform->addElement(
                'autocomplete',
                'assignees',
                get_string('assignees', 'mod_kanban'),
                $users,
                ['multiple' => true]
            );
        }

        $mform->addElement('editor', 'description_editor', get_string('description'), null, ['maxfiles' => -1]);
        $mform->setType('description_editor', PARAM_RAW);

        $mform->addElement('date_time_selector', 'duedate', get_string('duedate', 'kanban'), ['optional' => true]);

        $mform->addElement('date_time_selector', 'reminderdate', get_string('reminderdate', 'kanban'), ['optional' => true]);

        $repeatgroup = [];
        $repeatgroup[] = $mform->createElement('advcheckbox', 'repeat_enable', get_string('enable'));
        $repeatgroup[] = $mform->createElement('text', 'repeat_interval', get_string('repeat_interval', 'kanban'), ['size' => 3]);
        $repeatgroup[] = $mform->createElement('select', 'repeat_interval_type', get_string('repeat_interval_type', 'kanban'), [
            constants::MOD_KANBAN_REPEAT_HOURS => get_string('hours'),
            constants::MOD_KANBAN_REPEAT_DAYS => get_string('days'),
            constants::MOD_KANBAN_REPEAT_WEEKS => get_string('weeks'),
            constants::MOD_KANBAN_REPEAT_MONTHS => get_string('months'),
            constants::MOD_KANBAN_REPEAT_YEARS => get_string('years'),
        ]);
        $repeatgroup[] = $mform->createElement('select', 'repeat_newduedate', get_string('repeat_newduedate', 'kanban'), [
            constants::MOD_KANBAN_REPEAT_NONEWDUEDATE => get_string('nonewduedate', 'kanban'),
            constants::MOD_KANBAN_REPEAT_NEWDUEDATE_AFTERDUE => get_string('afterdue', 'kanban'),
            constants::MOD_KANBAN_REPEAT_NEWDUEDATE_AFTERCOMPLETION => get_string('aftercompletion', 'kanban'),
        ]);

        $mform->addElement('group', 'repeatgroup', get_string('repeat', 'kanban'), $repeatgroup, ' ', false);

        $mform->setType('repeat_interval', PARAM_INT);
        $mform->setType('repeat_interval_type', PARAM_INT);
        $mform->setDefault('repeat_enable', 0);
        $mform->setDefault('repeat_interval', 1);
        $mform->disabledIf('repeatgroup', 'repeat_enable');
        $mform->disabledIf('repeat_interval', 'repeat_newduedate', 'eq', constants::MOD_KANBAN_REPEAT_NONEWDUEDATE);
        $mform->disabledIf('repeat_interval_type', 'repeat_newduedate', 'eq', constants::MOD_KANBAN_REPEAT_NONEWDUEDATE);
        $mform->addHelpButton('repeatgroup', 'repeat', 'kanban');

        $mform->addElement('filemanager', 'attachments', get_string('attachments', 'kanban'));

        $mform->addElement('html', '<style>
            #fgroup_id_colorgroup .fgroup,
            #fgroup_id_colorgroup .felement.fgroup,
            #fitem_id_colorgroup .fgroup,
            #fitem_id_colorgroup .felement.fgroup {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: .55rem .7rem;
                max-width: 20rem;
            }
            #fgroup_id_colorgroup label,
            #fitem_id_colorgroup label {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 1.8rem;
                height: 1.8rem;
                margin: 0;
                cursor: pointer;
            }
            #fgroup_id_colorgroup .mod_kanban_cardcolor_option,
            #fitem_id_colorgroup .mod_kanban_cardcolor_option {
                appearance: none;
                -webkit-appearance: none;
                width: 1.2rem;
                height: 1.2rem;
                border: 1px solid #7a8494;
                border-radius: 50%;
                margin: 0;
                cursor: pointer;
                transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
            }
            #fgroup_id_colorgroup .mod_kanban_cardcolor_option:hover,
            #fgroup_id_colorgroup .mod_kanban_cardcolor_option:focus,
            #fitem_id_colorgroup .mod_kanban_cardcolor_option:hover,
            #fitem_id_colorgroup .mod_kanban_cardcolor_option:focus {
                transform: scale(1.08);
            }
            #fgroup_id_colorgroup .mod_kanban_cardcolor_option:checked,
            #fitem_id_colorgroup .mod_kanban_cardcolor_option:checked {
                border-color: #204f95;
                box-shadow: 0 0 0 2px #fff, 0 0 0 3px #204f95;
            }
        </style>');

        $cardcolors = [
            '#FFFFFF' => ['label' => get_string('cardcolorwhite', 'mod_kanban')],
            '#F6EEB9' => ['label' => get_string('cardcolorlightyellow', 'mod_kanban')],
            '#F8D0AF' => ['label' => get_string('cardcolorsoftorange', 'mod_kanban')],
            '#F7BBC0' => ['label' => get_string('cardcolorcoral', 'mod_kanban')],
            '#EFC2E9' => ['label' => get_string('cardcolorpink', 'mod_kanban')],
            '#D5C8F6' => ['label' => get_string('cardcolorlavender', 'mod_kanban')],
            '#D2E3FA' => ['label' => get_string('cardcolorlightblue', 'mod_kanban')],
            '#A9E5E1' => ['label' => get_string('cardcolorturquoise', 'mod_kanban')],
            '#E5F2BF' => ['label' => get_string('cardcolorlightlime', 'mod_kanban')],
        ];
        $colorelements = [];
        foreach ($cardcolors as $value => $meta) {
            $colorelements[] = $mform->createElement('radio', 'color', '', '', $value, [
                'class' => 'mod_kanban_cardcolor_option',
                'style' => 'appearance:none;-webkit-appearance:none;background:' . s($value) .
                    ';width:1.35rem;height:1.35rem;border-radius:50%;border:1px solid #7a8494;margin:0;',
                'title' => $meta['label'],
                'aria-label' => $meta['label'],
            ]);
        }
        $mform->addGroup($colorelements, 'colorgroup', get_string('color', 'mod_kanban'), '', false);
        $mform->setType('color', PARAM_TEXT);
        $mform->setDefault('color', '#FFFFFF');
    }

    /**
     * Returns context where this form is used
     *
     * @return context
     */
    protected function get_context_for_dynamic_submission(): context {
        $cmid = $this->optional_param('cmid', null, PARAM_INT);
        return context_module::instance($cmid);
    }

    /**
     * Checks if current user has access to this card, otherwise throws exception
     */
    protected function check_access_for_dynamic_submission(): void {
        global $COURSE;
        $context = $this->get_context_for_dynamic_submission();
        $cmid = $this->optional_param('cmid', null, PARAM_INT);
        $boardid = $this->optional_param('boardid', null, PARAM_INT);
        $kanbanboard = helper::get_cached_board($boardid);
        $id = $this->optional_param('id', null, PARAM_INT);
        $boardmanager = new boardmanager($cmid, $boardid);

        if (!$boardmanager->can_user_manage_specific_card($id)) {
            throw new moodle_exception('editing_this_card_is_not_allowed', 'mod_kanban');
        }

        $modinfo = get_fast_modinfo($COURSE);
        $cm = $modinfo->get_cm($cmid);
        helper::check_permissions_for_user_or_group($kanbanboard, $context, $cm);
    }

    /**
     * Process the form submission, used if form was submitted via AJAX
     *
     * @return array Returns whether a new template was created.
     */
    public function process_dynamic_submission(): array {
        $cmid = $this->optional_param('cmid', null, PARAM_INT);
        $boardid = $this->optional_param('boardid', null, PARAM_INT);
        $context = $this->get_context_for_dynamic_submission();
        $formdata = $this->get_data();

        $allowedcolors = [
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

        $selectedcolor = '';
        $requestcolor = strtoupper(trim((string)$this->optional_param('color', '', PARAM_RAW)));
        if (!empty($requestcolor)) {
            $selectedcolor = $requestcolor;
        } else if (!empty($formdata->color)) {
            $selectedcolor = strtoupper(clean_param($formdata->color, PARAM_TEXT));
        }
        if ($selectedcolor !== '') {
            $selectedcolor = ltrim($selectedcolor, '#');
            if (preg_match('/^[0-9A-F]{6}$/', $selectedcolor)) {
                $selectedcolor = '#' . $selectedcolor;
            }
        }
        if (!in_array($selectedcolor, $allowedcolors, true)) {
            $selectedcolor = '#FFFFFF';
        }
        $formdata->color = $selectedcolor;
        $formdata->background = $selectedcolor;
        $formdata->options = json_encode(['background' => $selectedcolor]);

        if (!has_capability('mod/kanban:assignothers', $context)) {
            unset($formdata->assignees);
        }

        $formdata->description = $formdata->description_editor['text'];
        $formdata->descriptionformat = $formdata->description_editor['format'];

        $formdata->description = file_save_draft_area_files(
            $formdata->attachments,
            $context->id,
            'mod_kanban',
            'attachments',
            $formdata->id,
            [],
            $formdata->description
        );

        $boardmanager = new boardmanager($cmid, $boardid);

        $boardmanager->update_card($formdata->id, (array) $formdata);

        return [
            'update' => $boardmanager->get_formatted_updates(),
        ];
    }

    /**
     * Load in existing data as form defaults
     */
    public function set_data_for_dynamic_submission(): void {
        global $DB;
        $context = $this->get_context_for_dynamic_submission();
        $id = $this->optional_param('id', null, PARAM_INT);
        $card = $DB->get_record('kanban_card', ['id' => $id]);
        $options = json_decode($card->options);
        $card->title = html_entity_decode($card->title, ENT_COMPAT, 'UTF-8');
        $card->cmid = $this->optional_param('cmid', null, PARAM_INT);
        $card->boardid = $card->kanban_board;
        $card->assignees = $DB->get_fieldset_select('kanban_assignee', 'userid', 'kanban_card = :cardid', ['cardid' => $id]);
        $card->color = empty($options->background) ? '#FFFFFF' : strtoupper(clean_param($options->background, PARAM_TEXT));
        $draftitemid = file_get_submitted_draft_itemid('attachments');
        $card->description = file_prepare_draft_area(
            $draftitemid,
            $context->id,
            'mod_kanban',
            'attachments',
            $card->id,
            [],
            $card->description
        );
        $card->description_editor['text'] = $card->description;
        $card->description_editor['format'] = $card->descriptionformat;
        $card->description_editor['itemid'] = $draftitemid;
        $card->attachments = $draftitemid;
        $this->set_data($card);
    }

    /**
     * Returns url to set in $PAGE->set_url() when form is being rendered or submitted via AJAX
     *
     * @return moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        $params = [
            'id' => $this->optional_param('id', null, PARAM_INT),
            'boardid' => $this->optional_param('boardid', null, PARAM_INT),
            'cmid' => $this->optional_param('cmid', null, PARAM_INT),
        ];
        return new moodle_url('/mod/kanban/view.php', $params);
    }
}

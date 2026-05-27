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
use mod_kanban\boardmanager;
use mod_kanban\helper;
use moodle_url;

/**
 * From for editing a column.
 *
 * @package    mod_kanban
 * @copyright   2023-2024 ISB Bayern
 * @author     Stefan Hanauska
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class edit_column_form extends dynamic_form {
    /**
     * Define the form
     */
    public function definition() {
        $mform =& $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $mform->addElement('hidden', 'boardid');
        $mform->setType('boardid', PARAM_INT);

        $mform->addElement('hidden', 'cmid');
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('text', 'title', get_string('columntitle', 'mod_kanban'), ['size' => '50']);
        $mform->setType('title', PARAM_TEXT);

        $userid = $this->optional_param('userid', 0, PARAM_INT);
        $groupid = $this->optional_param('groupid', 0, PARAM_INT);

        $mform->addElement('advcheckbox', 'autoclose', get_string('autoclose', 'mod_kanban'));
        $mform->setType('autoclose', PARAM_BOOL);

        $mform->addElement('advcheckbox', 'autohide', get_string('autohide', 'mod_kanban'));
        $mform->setType('autohide', PARAM_BOOL);

        $wiparray = [];
        $wiparray[] = $mform->createElement('advcheckbox', 'wiplimitenable', get_string('wiplimitenable', 'mod_kanban'));
        $wiparray[] = $mform->createElement('text', 'wiplimit', get_string('wiplimit', 'mod_kanban'), ['size' => '4']);
        $mform->addGroup($wiparray, 'wipgroup', '', '', false);

        $mform->setType('wiplimit', PARAM_INT);
        $mform->setType('wiplimitenable', PARAM_BOOL);

        $mform->disabledIf('wiplimit', 'wiplimitenable', 'notchecked');

        $mform->addElement('html', '<style>
            #fgroup_id_wipgroup .fgroup,
            #fgroup_id_wipgroup .felement.fgroup,
            #fitem_id_wipgroup .fgroup,
            #fitem_id_wipgroup .felement.fgroup {
                display: flex;
                align-items: center;
                gap: .3rem;
            }
            #fitem_id_wipgroup {
                margin-top: -.34rem;
                margin-bottom: .12rem;
            }
            #fgroup_id_wipgroup .fgroup > span:first-child,
            #fitem_id_wipgroup .fgroup > span:first-child {
                display: inline-flex;
                align-items: center;
                gap: .52rem;
                margin-right: .18rem;
            }
            #fgroup_id_wipgroup input[name=\"wiplimitenable\"],
            #fitem_id_wipgroup input[name=\"wiplimitenable\"] {
                margin-right: 0;
                position: relative;
                top: 1px;
            }
            #fgroup_id_wipgroup input[name=\"wiplimit\"],
            #fitem_id_wipgroup input[name=\"wiplimit\"] {
                margin-left: .52rem;
                width: 3.8rem;
                max-width: 3.8rem;
                padding: .22rem .4rem;
            }
            #fgroup_id_dotcolorgroup .fgroup,
            #fgroup_id_dotcolorgroup .felement.fgroup,
            #fitem_id_dotcolorgroup .fgroup,
            #fitem_id_dotcolorgroup .felement.fgroup {
                display: grid;
                grid-template-columns: repeat(5, minmax(0, 1fr));
                gap: .55rem .7rem;
                max-width: 13.5rem;
                margin-top: .75rem;
            }
            #fgroup_id_dotcolorgroup label,
            #fitem_id_dotcolorgroup label {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 1.8rem;
                height: 1.8rem;
                margin: 0;
                cursor: pointer;
            }
            #fgroup_id_dotcolorgroup .mod_kanban_dotcolor_option,
            #fitem_id_dotcolorgroup .mod_kanban_dotcolor_option {
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
            #fgroup_id_dotcolorgroup .mod_kanban_dotcolor_option:hover,
            #fgroup_id_dotcolorgroup .mod_kanban_dotcolor_option:focus,
            #fitem_id_dotcolorgroup .mod_kanban_dotcolor_option:hover,
            #fitem_id_dotcolorgroup .mod_kanban_dotcolor_option:focus {
                transform: scale(1.08);
            }
            #fgroup_id_dotcolorgroup .mod_kanban_dotcolor_option:checked,
            #fitem_id_dotcolorgroup .mod_kanban_dotcolor_option:checked {
                border-color: #204f95;
                box-shadow: 0 0 0 2px #fff, 0 0 0 3px #204f95;
            }
        </style>');

        $dotcolors = [
            '#9AA4B2' => ['label' => get_string('dotcolorgray', 'mod_kanban')],
            '#3579DC' => ['label' => get_string('dotcolorblue', 'mod_kanban')],
            '#4DB56A' => ['label' => get_string('dotcolorgreen', 'mod_kanban')],
            '#7C6ED6' => ['label' => get_string('dotcolorpurple', 'mod_kanban')],
            '#1D74A6' => ['label' => get_string('dotcolorcyan', 'mod_kanban')],
            '#009688' => ['label' => get_string('dotcolorteal', 'mod_kanban')],
            '#C68A2E' => ['label' => get_string('dotcoloramber', 'mod_kanban')],
            '#B96A55' => ['label' => get_string('dotcolorterracotta', 'mod_kanban')],
            '#A9597A' => ['label' => get_string('dotcolorrose', 'mod_kanban')],
            '#7A7A2E' => ['label' => get_string('dotcolorolive', 'mod_kanban')],
        ];
        $dotcolorelements = [];
        foreach ($dotcolors as $value => $meta) {
            $dotcolorelements[] = $mform->createElement('radio', 'dotcolor', '', '', $value, [
                'class' => 'mod_kanban_dotcolor_option',
                'style' => 'appearance:none;-webkit-appearance:none;background:' . s($value) .
                    ';width:1.35rem;height:1.35rem;border-radius:50%;border:1px solid #7a8494;margin:0;position:relative;top:5px;',
                'title' => $meta['label'],
                'aria-label' => $meta['label'],
            ]);
        }
        $mform->addGroup($dotcolorelements, 'dotcolorgroup', get_string('dotcolor', 'mod_kanban'), '', false);
        $mform->addHelpButton('dotcolorgroup', 'dotcolor', 'mod_kanban');
        $mform->setType('dotcolor', PARAM_TEXT);
        $mform->setDefault('dotcolor', '#9AA4B2');
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
        require_capability('mod/kanban:managecolumns', $context);
        $modinfo = get_fast_modinfo($COURSE);
        $cm = $modinfo->get_cm($cmid);
        \mod_kanban\helper::check_permissions_for_user_or_group($kanbanboard, $context, $cm);
    }

    /**
     * Process the form submission, used if form was submitted via AJAX
     *
     * @return array Returns whether a new template was created.
     */
    public function process_dynamic_submission(): array {
        global $COURSE;
        $formdata = $this->get_data();
        $cmid = $this->optional_param('cmid', null, PARAM_INT);
        $boardid = $this->optional_param('boardid', null, PARAM_INT);
        $modinfo = get_fast_modinfo($COURSE);
        $cminfo = $modinfo->get_cm($cmid);
        $context = $this->get_context_for_dynamic_submission();

        $boardmanager = new boardmanager($cmid, $boardid);

        helper::check_permissions_for_user_or_group($boardmanager->get_board(), $context, $cminfo);

        $boardmanager->update_column($formdata->id, (array) $formdata);

        return [
            'update' => $boardmanager->get_formatted_updates(),
        ];
    }

    /**
     * Load in existing data as form defaults
     */
    public function set_data_for_dynamic_submission(): void {
        global $DB;
        $id = $this->optional_param('id', null, PARAM_INT);
        $column = $DB->get_record('kanban_column', ['id' => $id]);
        $column->cmid = $this->optional_param('cmid', null, PARAM_INT);
        $column->title = html_entity_decode($column->title, ENT_COMPAT, 'UTF-8');
        $column->boardid = $column->kanban_board;
        $options = json_decode($column->options ?? '{}');
        if (empty($options) || !is_object($options)) {
            $options = (object)[];
        }
        $column->autoclose = !empty($options->autoclose);
        $column->autohide = !empty($options->autohide);
        $column->wiplimitenable = !empty($options->wiplimit);
        $column->wiplimit = (empty($options->wiplimit) ? 0 : $options->wiplimit);
        $column->dotcolor = empty($options->dotcolor) ? '' : strtoupper(clean_param($options->dotcolor, PARAM_TEXT));
        if (empty($column->dotcolor)) {
            $column->dotcolor = '#9AA4B2';
        }
        $this->set_data($column);
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

    /**
     * Validate the form data
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (!empty($data['wiplimitenable']) && $data['wiplimit'] <= 0) {
            $errors['wipgroup'] = get_string('wiplimitgreaterzero', 'mod_kanban');
        }

        return $errors;
    }
}

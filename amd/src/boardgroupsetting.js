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
 * Group board selector settings for mod_kanban.
 *
 * @module mod_kanban/boardgroupsetting
 * @copyright 2026
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(function() {
    /**
     * Sort select options by label.
     * @param {HTMLSelectElement} select
     */
    const sortOptions = function(select) {
        Array.from(select.options)
            .sort((a, b) => a.text.localeCompare(b.text))
            .forEach((option) => select.appendChild(option));
    };

    /**
     * Move selected options between selects.
     * @param {HTMLSelectElement} source
     * @param {HTMLSelectElement} target
     */
    const moveSelected = function(source, target) {
        let selectedOptions = Array.from(source.selectedOptions);
        if (!selectedOptions.length && source.selectedIndex >= 0) {
            selectedOptions = [source.options[source.selectedIndex]];
        }
        selectedOptions.forEach((option) => {
            target.appendChild(option);
            option.selected = true;
        });
        sortOptions(target);
        sortOptions(source);
    };

    return {
        init: function(param) {
            const availableSelect = document.getElementById('availableboardgroups');
            const selectedSelect = document.getElementById('id_selectedBoardGroups');
            const addBtn = document.getElementById('addBoardGroupButton');
            const removeBtn = document.getElementById('removeBoardGroupButton');
            const boardgroupsInput = document.getElementById('id_boardgroups');
            const boardgroupidInput = document.getElementById('id_boardgroupid');
            const boardmodeField = document.getElementById(param.boardmodefieldid);
            const container = document.getElementById(param.containerid);
            const form = document.getElementById(param.formid) || (selectedSelect ? selectedSelect.closest('form') : null);
            const containerRow = container ? (container.closest('.fitem') || container.parentElement) : null;
            const headerRow = containerRow && containerRow.previousElementSibling ? containerRow.previousElementSibling : null;

            if (!selectedSelect || !boardgroupsInput || !boardgroupidInput || !boardmodeField || !container) {
                return;
            }
            if (container.dataset.boardgroupsInit === '1') {
                return;
            }
            container.dataset.boardgroupsInit = '1';

            const syncInputs = function() {
                const selectedValues = Array.from(selectedSelect.options).map((option) => option.value);
                boardgroupsInput.value = selectedValues.join(',');
                boardgroupidInput.value = selectedValues.length ? selectedValues[0] : 0;
            };

            const toggleVisibility = function() {
                const showGroups = String(boardmodeField.value) === String(param.groupmodevalue);
                container.style.display = showGroups ? '' : 'none';
                if (containerRow) {
                    containerRow.style.display = showGroups ? '' : 'none';
                }
                if (headerRow) {
                    headerRow.style.display = showGroups ? '' : 'none';
                }
            };

            if (availableSelect && addBtn) {
                addBtn.addEventListener('click', function(event) {
                    event.preventDefault();
                    moveSelected(availableSelect, selectedSelect);
                    syncInputs();
                });
                availableSelect.addEventListener('dblclick', function(event) {
                    event.preventDefault();
                    moveSelected(availableSelect, selectedSelect);
                    syncInputs();
                });
            }

            if (removeBtn) {
                removeBtn.addEventListener('click', function(event) {
                    event.preventDefault();
                    moveSelected(selectedSelect, availableSelect);
                    syncInputs();
                });
            }

            selectedSelect.addEventListener('dblclick', function(event) {
                event.preventDefault();
                if (availableSelect) {
                    moveSelected(selectedSelect, availableSelect);
                    syncInputs();
                }
            });

            boardmodeField.addEventListener('change', toggleVisibility);
            if (form) {
                form.addEventListener('submit', function() {
                    Array.from(selectedSelect.options).forEach((option) => {
                        option.selected = true;
                    });
                    syncInputs();
                });
            }

            syncInputs();
            toggleVisibility();
        }
    };
});

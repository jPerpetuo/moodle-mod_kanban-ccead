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
 *  Component representing a card in a kanban board.
 *
 * @module     mod_kanban/card
 * @copyright  2024 ISB Bayern
 * @author     Stefan Hanauska <stefan.hanauska@csg-in.de>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import {DragDrop} from 'core/reactive';
import selectors from 'mod_kanban/selectors';
import exporter from 'mod_kanban/exporter';
import {alert, exception as displayException, saveCancel} from 'core/notification';
import ModalForm from 'core_form/modalform';
import ModalEvents from 'core/modal_events';
import * as Str from 'core/str';
import {get_string as getString} from 'core/str';
import Templates from 'core/templates';
import KanbanComponent from 'mod_kanban/kanbancomponent';
import Log from 'core/log';

/**
 * Component representing a card in a kanban board.
 */
export default class extends KanbanComponent {
    /**
     * For relative time helper.
     */
    _units = {
        year: 24 * 60 * 60 * 1000 * 365,
        month: 24 * 60 * 60 * 1000 * 365 / 12,
        day: 24 * 60 * 60 * 1000,
        hour: 60 * 60 * 1000,
        minute: 60 * 1000,
        second: 1000
    };

    /**
     * Function to initialize component, called by mustache template.
     * @param {*} target The id of the HTMLElement to attach to
     * @returns {BaseComponent} New component attached to the HTMLElement represented by target
     */
    static init(target) {
        let element = document.getElementById(target);
        return new this({
            element: element,
        });
    }

    /**
     * Called after the component was created.
     */
    create() {
        this.id = this.element.dataset.id;
        this.element.__modKanbanCardComponent = this;
    }

    /**
     * Watchers for this component.
     * @returns {array} All watchers for this component
     */
    getWatchers() {
        return [
            {watch: `cards[${this.id}]:updated`, handler: this._cardUpdated},
            {watch: `cards[${this.id}]:deleted`, handler: this._cardDeleted},
            {watch: `discussions:created`, handler: this._discussionUpdated},
            {watch: `discussions:updated`, handler: this._discussionUpdated},
            {watch: `discussions:deleted`, handler: this._discussionUpdated},
            {watch: `history:created`, handler: this._historyUpdated},
            {watch: `history:updated`, handler: this._historyUpdated},
            {watch: `history:deleted`, handler: this._historyUpdated},
        ];
    }

    /**
     * Called once when state is ready (also if component is registered after initial state was set), attaching event
     * isteners and initializing drag and drop.
     * @param {*} state The initial state
     */
    stateReady(state) {
        // Get language for relative time formatting.
        let lang = 'en';
        if (state.common.lang !== undefined) {
            lang = state.common.lang;
        }
        // The property state.common.lang contains the locale extracted from the currently used moodle language pack.
        // This should be a real locale and thus suitable for RelativeTimeFormat, for edge cases however we are
        // using a fallback locale here.
        try {
            this.rtf = new Intl.RelativeTimeFormat(lang, {numeric: 'auto'});
        } catch (e) {
            // Fallback if there is no valid lang found.
            this.rtf = new Intl.RelativeTimeFormat('en', {numeric: 'auto'});
        }

        this.addEventListener(
            this.getElement(selectors.DELETECARD, this.id),
            'click',
            this._removeConfirm
        );
        this.addEventListener(
            this.getElement(selectors.ADDCARD, this.id),
            'click',
            this._addCard
        );
        this.addEventListener(
            this.getElement(selectors.COMPLETE, this.id),
            'click',
            this._completeCard
        );
        this.addEventListener(
            this.getElement(selectors.UNCOMPLETE, this.id),
            'click',
            this._uncompleteCard
        );
        this.addEventListener(
            this.getElement(selectors.ASSIGNSELF, this.id),
            'click',
            this._assignSelf
        );
        this.addEventListener(
            this.getElement(selectors.UNASSIGNSELF, this.id),
            'click',
            this._unassignSelf
        );
        this.addEventListener(
            this.getElement(selectors.EDITDETAILS, this.id),
            'click',
            this._editDetails
        );
        this.addEventListener(
            this.getElement(selectors.DISCUSSIONMODALTRIGGER),
            'click',
            this._updateDiscussion
        );
        this.addEventListener(
            this.getElement(selectors.DISCUSSIONSHOW, this.id),
            'click',
            this._updateDiscussion
        );
        this.addEventListener(
            this.getElement(selectors.DISCUSSIONSEND),
            'click',
            this._sendMessage
        );
        this.addEventListener(
            this.getElement(selectors.HISTORYMODALTRIGGER),
            'click',
            this._updateHistory
        );
        this.addEventListener(
            this.getElement(selectors.MOVEMODALTRIGGER),
            'click',
            this._showMoveModal
        );
        this.addEventListener(
            this.getElement(selectors.PUSHCARD),
            'click',
            this._pushCardConfirm
        );
        this.addEventListener(
            this.getElement(selectors.DUPLICATE),
            'click',
            this._duplicateCard
        );
        this.addEventListener(
            this.getElement(selectors.DETAILBUTTON),
            'click',
            this._showDetailsModal
        );

        this.draggable = false;
        this.dragdrop = new DragDrop(this);
        this.checkEditing(state);
        this.boardid = state.board.id;
        this.cmid = state.common.id;
        this.userid = state.board.userid;
        this.groupid = state.board.groupid;
        this._dueDateFormat();
        this._updateCompletionIndicatorTooltip();
        this._syncFooterLayoutState();
    }

    /**
     * Show modal to move a column.
     */
    _showMoveModal() {
        let data = exporter.exportStateForTemplate(this.reactive.state);
        data.cardid = this.id;
        data.kanbancolumn = this.reactive.state.cards.get(this.id).kanban_column;
        Str.get_strings([
            {key: 'movecard', component: 'mod_kanban'},
            {key: 'move', component: 'core'},
        ]).then((strings) => {
            return saveCancel(
                strings[0],
                Templates.render('mod_kanban/movemodal', data),
                strings[1],
                () => {
                    let column = document.querySelector(selectors.MOVECARDCOLUMN + `[data-id="${this.id}"]`).value;
                    let aftercard = document.querySelector(selectors.MOVECARDAFTERCARD + `[data-id="${this.id}"]`).value;
                    this.reactive.dispatch('moveCard', this.id, column, aftercard);
                }
            );
        }).catch((error) => Log.debug(error));
    }

    /**
     * Show modal with card details.
     * @param {*} event Click event.
     */
    _showDetailsModal(event) {
        let id = this.id;
        if (event.target.dataset.id !== undefined) {
            id = event.target.dataset.id;
        }

        let data = exporter.exportCard(this.reactive.state, id);
        let title = this.reactive.state.common.usenumbers ? '#' + data.number + ' ' + data.title : data.title;

        alert(
            title,
            Templates.render('mod_kanban/descriptionmodal', data),
            getString('close', 'form')
        ).then((modal) => {
            modal.modal[0].addEventListener(ModalEvents.bodyRendered, () => {
                document.querySelectorAll(selectors.CARDNUMBER).forEach((el) => {
                    this.removeEventListener(el, 'click', this._clickDetailsButton);
                    this.addEventListener(el, 'click', this._clickDetailsButton);
                });
            });
            return true;
        }).catch((error) => Log.debug(error));
    }

    /**
     * Simulate click on details button.
     * @param {*} event Click event.
     */
    _clickDetailsButton(event) {
        document.querySelector(
            selectors.CARD + `[data-number="${event.target.dataset.id}"]` + ' ' + selectors.DETAILBUTTON
        ).click();
    }

    /**
     * Display confirmation modal for pushing a card.
     * @param {*} event
     */
    _pushCardConfirm(event) {
        Str.get_strings([
            {key: 'pushcard', component: 'mod_kanban'},
            {key: 'pushcardconfirm', component: 'mod_kanban'},
            {key: 'copy', component: 'core'},
        ]).then((strings) => {
            return saveCancel(
                strings[0],
                strings[1],
                strings[2],
                () => {
                    this._pushCard(event);
                }
            );
        }).catch((error) => Log.debug(error));
    }

    /**
     * Display confirmation modal for deleting a card.
     * @param {*} event
     */
    _removeConfirm(event) {
        Str.get_strings([
            {key: 'deletecard', component: 'mod_kanban'},
            {key: 'deletecardconfirm', component: 'mod_kanban'},
            {key: 'delete', component: 'core'},
        ]).then((strings) => {
            return saveCancel(
                strings[0],
                strings[1],
                strings[2],
                () => {
                    this._removeCard(event);
                }
            );
        }).catch((error) => Log.debug(error));
    }

    /**
     * Display confirmation modal for deleting a discussion message.
     * @param {*} event
     */
    _removeMessageConfirm(event) {
        Str.get_strings([
            {key: 'deletemessage', component: 'mod_kanban'},
            {key: 'deletemessageconfirm', component: 'mod_kanban'},
            {key: 'delete', component: 'core'},
        ]).then((strings) => {
            return saveCancel(
                strings[0],
                strings[1],
                strings[2],
                () => {
                    this._removeMessage(event);
                }
            );
        }).catch((error) => Log.debug(error));
    }

    /**
     * Dispatch event to add a message to discussion.
     */
    async _sendMessage() {
        let el = this.getElement(selectors.DISCUSSIONINPUT);
        let message = el.value.trim();
        if (message != '') {
            // Optimistic UI: show discussion indicator immediately.
            const statecard = this.reactive?.state?.cards?.get(this.id);
            if (statecard && !statecard.discussion) {
                this._applyOptimisticCardPatch({
                    id: this.id,
                    discussion: 1,
                });
            }
            const original = el.value;
            el.value = '';
            try {
                await Promise.resolve(this.reactive.dispatch('sendDiscussionMessage', this.id, message));
                await Promise.resolve(this.reactive.dispatch('getDiscussionUpdates', this.id));
            } catch (e) {
                el.value = original;
                displayException(e);
            }
        }
    }

    /**
     * Dispatch event to update the discussion data.
     */
    _updateDiscussion() {
        this.getElement(selectors.DISCUSSIONMODAL).classList.add('mod_kanban_loading');
        this.reactive.dispatch('getDiscussionUpdates', this.id);
    }

    /**
     * Called when discussion was updated.
     */
    async _discussionUpdated() {
        let data = {
            discussions: exporter.exportDiscussion(this.reactive.state, this.id)
        };
        Templates.renderForPromise('mod_kanban/discussionmessages', data).then(({html}) => {
            this.getElement(selectors.DISCUSSION, this.id).innerHTML = html;
            this.getElement(selectors.DISCUSSIONMODAL, this.id).classList.remove('mod_kanban_loading');
            let el = this.getElement(selectors.DISCUSSIONMESSAGES);
            // Scroll down to latest message.
            el.scrollTop = el.scrollHeight;
            data.discussions.forEach((d) => {
                if (d.candelete) {
                    this.addEventListener(this.getElement(selectors.DELETEMESSAGE, d.id), 'click', this._removeMessageConfirm);
                }
            });
            this.getElement(selectors.DISCUSSION).querySelectorAll(selectors.CARDNUMBER).forEach((el) => {
                this.removeEventListener(el, 'click', this._clickDetailsButton);
                this.addEventListener(el, 'click', this._clickDetailsButton);
            });
            return true;
        }).catch((error) => displayException(error));
    }

    /**
     * Dispatch event to update the history data.
     */
    _updateHistory() {
        this.getElement(selectors.HISTORYMODAL).classList.add('mod_kanban_loading');
        this.reactive.dispatch('getHistoryUpdates', this.id);
    }

    /**
     * Called when history was updated.
     */
    async _historyUpdated() {
        let data = {
            historyitems: exporter.exportHistory(this.reactive.state, this.id)
        };
        Templates.renderForPromise('mod_kanban/historyitems', data).then(({html}) => {
            this.getElement(selectors.HISTORY, this.id).innerHTML = html;
            this.getElement(selectors.HISTORYMODAL).classList.remove('mod_kanban_loading');
            // Scroll down to latest history item.
            let el = this.getElement(selectors.HISTORYITEMS);
            el.scrollTop = el.scrollHeight;
            return true;
        }).catch((error) => displayException(error));
    }

    /**
     * Dispatch event to assign the current user to the card.
     * @param {*} event
     */
    _assignSelf(event) {
        let target = event.target.closest(selectors.ASSIGNSELF);
        let data = Object.assign({}, target.dataset);
        const statecard = this.reactive?.state?.cards?.get(data.id);
        const userid = String(this.reactive?.state?.board?.userid || this.userid || '');
        if (statecard && userid) {
            const assignees = Array.isArray(statecard.assignees) ? [...statecard.assignees] : [];
            const alreadyassigned = assignees.some((id) => String(id) === userid);
            if (!alreadyassigned) {
                assignees.push(parseInt(userid, 10));
                this._applyOptimisticCardPatch({
                    id: data.id,
                    assignees: assignees,
                    selfassigned: true,
                });
            }
        }
        this.reactive.dispatch('assignUser', data.id);
    }

    /**
     * Dispatch event to add a card after this card.
     * @param {*} event
     */
    _addCard(event) {
        document.activeElement.blur();
        let target = event.target.closest(selectors.ADDCARD);
        let data = Object.assign({}, target.dataset);
        this.reactive.dispatch('addCard', data.columnid, data.id);
    }

    /**
     * Called when card is updated.
     * @param {*} param0
     */
    async _cardUpdated({element}) {
        if (this.getElement().querySelector('.mod_kanban_card_title_editor')) {
            return;
        }
        const card = this.getElement();
        // Card was moved to another column. Move the element to new card (right position is handled by column component).
        if (card.dataset.columnid != element.kanban_column) {
            const col = document.querySelector(selectors.COLUMNINNER + '[data-id="' + element.kanban_column + '"]');
            col.appendChild(card);
            this.getElement(selectors.ADDCARD, this.id).setAttribute('data-columnid', element.kanban_column);
            card.setAttribute('data-columnid', element.kanban_column);
        }
        const assignees = this.getElement(selectors.ASSIGNEES, this.id);
        const assignedUsers = this.getElements(selectors.ASSIGNEDUSER, this.id);
        const userids = [...assignedUsers].map(v => {
            return v.dataset.userid;
        });
        // Update assignees.
        if (element.assignees !== undefined) {
            const additional = element.assignees.filter(x => !userids.includes(x));
            // Remove all elements that represent users that are no longer assigned to this card.
            if (assignedUsers !== null) {
                assignedUsers.forEach(assignedUser => {
                    if (!element.assignees.includes(assignedUser.dataset.userid)) {
                        assignedUser.parentNode.removeChild(assignedUser);
                    }
                });
            }
            this.toggleClass(element.assignees.length == 0, 'mod_kanban_unassigned');
            // Add new assignees.
            if (element.assignees.length > 0) {
                additional.forEach(async user => {
                    let userdata = this.reactive.state.users.get(user);
                    let data = Object.assign({cardid: element.id}, userdata);
                    data = Object.assign(data, exporter.exportCapabilities(this.reactive.state));
                    Templates.renderForPromise('mod_kanban/user', data).then(({html, js}) => {
                        Templates.appendNodeContents(assignees, html, js);
                        return true;
                    }).catch((error) => displayException(error));
                });
            }
        }
        const assigneesRow = this.getElement().querySelector('.mod_kanban_card_assignees_row');
        if (assigneesRow) {
            const hasassignees = element.assignees !== undefined ?
                element.assignees.length > 0 :
                this.getElements(selectors.ASSIGNEDUSER, this.id).length > 0;
            assigneesRow.classList.toggle('has-assignees', hasassignees);
        }
        if (element.selfassigned !== undefined) {
            this.toggleClass(element.selfassigned, 'mod_kanban_selfassigned');
        }
        // Set card completion state.
        if (element.completed !== undefined) {
            this.toggleClass(element.completed == 1, 'mod_kanban_closed');
        }
        if (element.completedat !== undefined) {
            this.getElement().setAttribute('data-completedat', element.completedat);
        }
        this._updateCompletionIndicatorTooltip();
        // Update title (also in modals).
        if (element.title !== undefined) {
            const cardelement = this.getElement();
            const pendingtitlesave = cardelement.dataset.titleSavePending === '1';
            // For Moodle inplace editing title is once needed plain and once with html entities encoded.
            // This avoids double encoding of html entities as the value of "data-value" is exactly what is shown
            // in the input field when clicking on the inplace editable.
            let doc = new DOMParser().parseFromString(element.title, 'text/html');
            const incomingtitleplain = doc.documentElement.textContent || '';
            if (pendingtitlesave) {
                const expectedtitle = cardelement.dataset.titleSaveExpected || '';
                if (incomingtitleplain !== expectedtitle) {
                    // Ignore stale title update while local title save is pending.
                    return;
                }
                delete cardelement.dataset.titleSavePending;
                delete cardelement.dataset.titleSaveExpected;
                delete cardelement.dataset.titleSaveSeq;
            }
            const lockuntilraw = cardelement.dataset.titleLockUntil || '0';
            const lockuntil = parseInt(lockuntilraw, 10) || 0;
            const lockvalue = cardelement.dataset.titleLockValue || '';
            if (lockuntil > Date.now()) {
                if (lockvalue && incomingtitleplain !== lockvalue) {
                    // Ignore stale updates during short post-save lock window.
                    return;
                }
            } else {
                delete cardelement.dataset.titleLockUntil;
                delete cardelement.dataset.titleLockValue;
            }
            this.getElement(selectors.INPLACEEDITABLE).setAttribute('data-value', incomingtitleplain);
            this.getElement(selectors.INPLACEEDITABLE).querySelector('a').innerHTML = element.title;
            this.getElement(selectors.DISCUSSIONMODALTITLE).innerHTML = element.title;
        }
        if (element.hasdescription !== undefined) {
            this.toggleClass(element.hasdescription, 'mod_kanban_hasdescription');
        }
        if (element.hasattachment !== undefined) {
            this.toggleClass(element.hasattachment, 'mod_kanban_hasattachment');
        }
        // Update due date.
        if (element.duedate !== undefined) {
            this.getElement(selectors.DUEDATE).setAttribute('data-date', element.duedate);
            this._dueDateFormat();
        }
        if (element.discussion !== undefined) {
            this.toggleClass(element.discussion, 'mod_kanban_hasdiscussion');
        }
        // Only option for now is background color.
        if (element.options !== undefined) {
            let options = JSON.parse(element.options);
            if (options.background === undefined) {
                this.getElement().removeAttribute('style');
            } else {
                this.getElement().setAttribute('style', 'background-color: ' + options.background);
            }
        }
        const metaRow = this.getElement().querySelector('.mod_kanban_card_meta_row');
        if (metaRow) {
            const duedate = Number(this.getElement(selectors.DUEDATE).dataset.date);
            metaRow.classList.toggle(
                'has-meta',
                duedate > 0 ||
                this.getElement().classList.contains('mod_kanban_closed') ||
                this.getElement().classList.contains('mod_kanban_hasdiscussion') ||
                this.getElement().classList.contains('mod_kanban_hasdescription') ||
                this.getElement().classList.contains('mod_kanban_hasattachment')
            );
        }
        this._syncFooterLayoutState();
        // Enable/disable dragging and inplace editing (e.g. if user is not assigned to the card anymore).
        this.checkEditing();
    }

    /**
     * Delete this card.
     */
    _cardDeleted() {
        this.destroy();
    }

    /**
     * Dispatch event to remove this card.
     * @param {*} event
     */
    _removeCard(event) {
        let target = event.target.closest(selectors.DELETECARD);
        let data = Object.assign({}, target.dataset);
        this.reactive.dispatch('deleteCard', data.id);
    }

    /**
     * Dispatch event to push this card.
     * @param {*} event
     */
    _pushCard(event) {
        let target = event.target.closest(selectors.PUSHCARD);
        let data = Object.assign({}, target.dataset);
        this.reactive.dispatch('pushCard', data.id);
    }

    /**
     * Dispatch event to remove this card.
     * @param {*} event
     */
    _removeMessage(event) {
        let target = event.target.closest(selectors.DELETEMESSAGE);
        let data = Object.assign({}, target.dataset);
        this.reactive.dispatch('deleteMessage', data.id);
    }

    /**
     * Dispatch event to complete this card.
     * @param {*} event
     */
    _completeCard(event) {
        let target = event.target.closest(selectors.COMPLETE);
        let data = Object.assign({}, target.dataset);
        this.reactive.dispatch('completeCard', data.id);
    }

    /**
     * Dispatch event to complete this card.
     * @param {*} event
     */
    _uncompleteCard(event) {
        let target = event.target.closest(selectors.UNCOMPLETE);
        let data = Object.assign({}, target.dataset);
        this.reactive.dispatch('uncompleteCard', data.id);
    }

    /**
     * Remove all subcomponents dependencies.
     */
    destroy() {
        if (this.dragdrop !== undefined) {
            this.dragdrop.unregister();
        }
    }

    /**
     * Get the draggable data of this component.
     * @returns {object}
     */
    getDraggableData() {
        return {
            id: this.id,
            type: 'card',
        };
    }

    /**
     * Conditionally enable / disable dragging and inplace editing.
     * @param {*} state
     */
    checkEditing(state) {
        if (state === undefined) {
            state = this.reactive.stateManager.state;
        }
        if (state.cards.get(this.id).canedit) {
            this.draggable = true;
            this.dragdrop.setDraggable(true);
        } else {
            this.draggable = false;
            this.dragdrop.setDraggable(false);
        }
        if (state.cards.get(this.id).completed != 1 && state.cards.get(this.id).canedit) {
            this.getElement(selectors.INPLACEEDITABLE).setAttribute('data-inplaceeditable', '1');
        } else {
            this.getElement(selectors.INPLACEEDITABLE).removeAttribute('data-inplaceeditable');
        }

        this.toggleClass(state.cards.get(this.id).canedit, 'mod_kanban_canedit');
    }

    /**
     * Validate draggable data.
     * @param {object} dropdata
     * @returns {boolean} if the data is valid for this drop-zone.
     */
    validateDropData(dropdata) {
        return dropdata?.type == 'card';
    }

    /**
     * Executed when a valid dropdata is dropped over the drop-zone.
     * @param {object} dropdata
     */
    drop(dropdata) {
        if (dropdata.id != this.id) {
            let newcolumn = this.getElement(selectors.ADDCARD, this.id).dataset.columnid;
            let aftercard = this.id;
            this.reactive.dispatch('moveCard', dropdata.id, newcolumn, aftercard);
        }
    }

    /**
     * Dispatch event to unassign the current user.
     * @param {*} event
     */
    _unassignSelf(event) {
        let target = event.target.closest(selectors.UNASSIGNSELF);
        let data = Object.assign({}, target.dataset);
        const statecard = this.reactive?.state?.cards?.get(data.id);
        const userid = String(this.reactive?.state?.board?.userid || this.userid || '');
        if (statecard && userid) {
            const assignees = Array.isArray(statecard.assignees) ? [...statecard.assignees] : [];
            const updatedassignees = assignees.filter((id) => String(id) !== userid);
            this._applyOptimisticCardPatch({
                id: data.id,
                assignees: updatedassignees,
                selfassigned: false,
            });
        }
        this.reactive.dispatch('unassignUser', data.id);
    }

    /**
     * Apply a local optimistic card patch before backend confirmation.
     * @param {Object} fields
     */
    _applyOptimisticCardPatch(fields) {
        if (!this.reactive || !this.reactive.stateManager || !fields || fields.id === undefined) {
            return;
        }
        const existing = this.reactive?.state?.cards?.get(fields.id);
        if (!existing) {
            return;
        }
        const merged = Object.assign({}, existing, fields);
        this.reactive.stateManager.processUpdates([{
            name: 'cards',
            action: 'put',
            fields: merged,
        }]);
    }

    /**
     * Keep footer layout compact when card has assignee and meta icons but no due date.
     */
    _syncFooterLayoutState() {
        const card = this.getElement();
        if (!card) {
            return;
        }
        const assigneesrow = card.querySelector('.mod_kanban_card_assignees_row');
        const metarow = card.querySelector('.mod_kanban_card_meta_row');
        if (!assigneesrow || !metarow) {
            return;
        }
        const hasassignees = assigneesrow.classList.contains('has-assignees');
        const hasmeta = metarow.classList.contains('has-meta');
        const hasduedate = card.classList.contains('mod_kanban_hasduedate');
        const isclosed = card.classList.contains('mod_kanban_closed');
        card.classList.toggle('mod_kanban_footer_compact', !isclosed && !hasduedate && hasassignees && hasmeta);
    }

    /**
     * Show modal form to edit card details.
     * @param {*} event
     */
    _editDetails(event) {
        event.preventDefault();

        const modalForm = new ModalForm({
            formClass: "mod_kanban\\form\\edit_card_form",
            args: {
                id: this.id,
                boardid: this.boardid,
                cmid: this.cmid,
                groupid: this.groupid,
                userid: this.userid
            },
            modalConfig: {title: getString('editcard', 'mod_kanban')},
            returnFocus: this.getElement(),
        });
        this.addEventListener(modalForm, modalForm.events.FORM_SUBMITTED, this._updateCard);
        modalForm.show();
    }

    /**
     * Dispatch an event to update card data from the detail modal.
     * @param {*} event
     */
    _updateCard(event) {
        this.reactive.dispatch('processUpdates', event.detail);
    }

    /**
     * Update relative time.
     * @param {int} timestamp
     * @returns {string}
     */
    updateRelativeTime(timestamp) {
        let elapsed = new Date(timestamp) - new Date();
        for (var u in this._units) {
            if (Math.abs(elapsed) > this._units[u] || u == 'second') {
                return this.rtf.format(Math.round(elapsed / this._units[u]), u);
            }
        }
        return '';
    }

    /**
     * Format due date as the final card date.
     * @param {int} timestamp
     * @returns {string}
     */
    formatDueDate(timestamp) {
        const lang = this.reactive.state.common.lang || 'pt-BR';
        return new Intl.DateTimeFormat(lang, {
            day: '2-digit',
            month: '2-digit',
        }).format(new Date(timestamp));
    }

    /**
     * Format due date for tooltip using an absolute date.
     * @param {int} timestamp
     * @returns {string}
     */
    formatDueDateTooltip(timestamp) {
        const lang = this.reactive.state.common.lang || 'pt-BR';
        return new Intl.DateTimeFormat(lang, {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        }).format(new Date(timestamp));
    }

    /**
     * Format completion date for tooltip.
     * @param {int} timestamp Timestamp in seconds.
     * @returns {string}
     */
    formatCompletedAtTooltip(timestamp) {
        const lang = this.reactive.state.common.lang || 'pt-BR';
        return new Intl.DateTimeFormat(lang, {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(timestamp * 1000));
    }

    /**
     * Update completed checkmark tooltip from card state/history timestamp.
     */
    _updateCompletionIndicatorTooltip() {
        const card = this.getElement();
        if (!card) {
            return;
        }
        const indicator = card.querySelector(selectors.COMPLETIONINDICATOR);
        if (!indicator) {
            return;
        }
        const fallback = indicator.dataset.completedFallback || 'Completed';
        if (!card.classList.contains('mod_kanban_closed')) {
            indicator.setAttribute('title', fallback);
            return;
        }
        const historyenabled = !!(this.reactive?.state?.common?.history);
        if (!historyenabled) {
            indicator.setAttribute('title', fallback);
            return;
        }
        const completedat = parseInt(card.dataset.completedat || '0', 10) || 0;
        let completedline = fallback;
        if (completedat > 0) {
            const label = indicator.dataset.completedLabel || fallback;
            completedline = `${label}: ${this.formatCompletedAtTooltip(completedat)}`;
        }
        const duedateelement = card.querySelector(selectors.DUEDATE);
        const duedate = parseInt(duedateelement?.dataset?.date || '0', 10) || 0;
        if (duedate > 0) {
            const plannedlabel = indicator.dataset.plannedLabel || 'Planned for';
            const plannedline = `${plannedlabel}: ${this.formatCompletedAtTooltip(duedate)}`;
            indicator.setAttribute('title', `${plannedline}\n${completedline}`);
            return;
        }
        indicator.setAttribute('title', completedline);
    }

    /**
     * Format due date using relative time.
     * @param {int} timestamp
     * @returns {string}
     */
    formatRelativeDueDate(timestamp) {
        return this.updateRelativeTime(timestamp);
    }

    /**
     * Format due date using relative time and absolute tooltip.
     */
    _dueDateFormat() {
        const element = this.getElement(selectors.DUEDATE);
        const card = this.getElement();
        let text = element.querySelector('.mod_kanban_duedate_text');
        const duedate = element.dataset.date * 1000;

        if (duedate > 0) {
            card.classList.add('mod_kanban_hasduedate');
            const overdue = duedate < new Date().getTime();
            if (!text) {
                element.innerHTML = '<span class="mod_kanban_duedate_icon"></span>' +
                    '<span class="mod_kanban_duedate_text"></span>';
                text = element.querySelector('.mod_kanban_duedate_text');
            }
            const icon = element.querySelector('.mod_kanban_duedate_icon');
            if (icon) {
                icon.innerHTML = overdue
                    ? '<i class="icon fa fa-exclamation-circle fa-fw" aria-hidden="true"></i>'
                    : '<i class="icon fa-regular fa-calendar-days fa-fw" aria-hidden="true"></i>';
            }
            if (text) {
                text.innerHTML = this.formatRelativeDueDate(duedate);
            } else {
                element.innerHTML = this.formatRelativeDueDate(duedate);
            }
            element.setAttribute('title', this.formatDueDateTooltip(duedate));
            if (overdue) {
                element.classList.add('mod_kanban_overdue');
            } else {
                element.classList.remove('mod_kanban_overdue');
            }
        } else {
            card.classList.remove('mod_kanban_hasduedate');
            element.innerHTML = '';
        }
    }

    /**
     * Dispatch event to duplicate this card.
     * @param {*} event Click event.
     */
    _duplicateCard(event) {
        let target = event.target.closest(selectors.DUPLICATE);
        let data = Object.assign({}, target.dataset);
        this.reactive.dispatch('duplicateCard', data.id);
    }
}

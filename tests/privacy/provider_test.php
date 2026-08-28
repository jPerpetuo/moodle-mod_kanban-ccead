<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tests for the mod_kanban privacy provider.
 *
 * @package    mod_kanban
 * @copyright  2026 CCEAD PUC-Rio
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_kanban\privacy;

use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\request\approved_contextlist;
use mod_kanban\boardmanager;
use mod_kanban\helper;

/**
 * Tests for the mod_kanban privacy provider.
 *
 * @covers \mod_kanban\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass Kanban instance. */
    private $kanban;
    /** @var \context_module Module context. */
    private $context;
    /** @var \stdClass User whose data is deleted. */
    private $targetuser;
    /** @var \stdClass User whose data must remain. */
    private $otheruser;
    /** @var \stdClass Shared board. */
    private $sharedboard;
    /** @var \stdClass Shared card. */
    private $sharedcard;
    /** @var int Personal board id. */
    private $personalboardid;
    /** @var int Personal card id. */
    private $personalcardid;
    /** @var int History created by target user. */
    private $targethistoryid;
    /** @var int History affecting target user. */
    private $affectedhistoryid;

    /**
     * Prepare representative personal data.
     */
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $this->kanban = $this->getDataGenerator()->create_module('kanban', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('kanban', $this->kanban->id, $course->id, false, MUST_EXIST);
        $this->context = \context_module::instance($cm->id);
        $this->targetuser = $this->getDataGenerator()->create_user();
        $this->otheruser = $this->getDataGenerator()->create_user();

        $this->sharedboard = $DB->get_record('kanban_board', [
            'kanban_instance' => $this->kanban->id,
            'userid' => 0,
            'groupid' => 0,
            'template' => 0,
        ], '*', MUST_EXIST);
        $columnid = $DB->get_field('kanban_column', 'id', [
            'kanban_board' => $this->sharedboard->id,
        ], IGNORE_MULTIPLE);
        $this->sharedcard = $this->create_card(
            $this->sharedboard->id,
            $columnid,
            $this->targetuser->id,
            'Shared card'
        );

        $DB->insert_record('kanban_assignee', [
            'kanban_card' => $this->sharedcard->id,
            'userid' => $this->targetuser->id,
        ]);
        $DB->insert_record('kanban_assignee', [
            'kanban_card' => $this->sharedcard->id,
            'userid' => $this->otheruser->id,
        ]);
        $DB->insert_record('kanban_discussion_comment', [
            'kanban_card' => $this->sharedcard->id,
            'userid' => $this->targetuser->id,
            'content' => 'Target comment',
            'timecreated' => time(),
        ]);
        $DB->insert_record('kanban_discussion_comment', [
            'kanban_card' => $this->sharedcard->id,
            'userid' => $this->otheruser->id,
            'content' => 'Other comment',
            'timecreated' => time(),
        ]);
        $this->targethistoryid = $this->create_history($this->targetuser->id, 0);
        $this->affectedhistoryid = $this->create_history($this->otheruser->id, $this->targetuser->id);

        $manager = new boardmanager($cm->id);
        $this->personalboardid = $manager->get_or_create_board($this->targetuser->id);
        $personalcolumnid = $DB->get_field('kanban_column', 'id', [
            'kanban_board' => $this->personalboardid,
        ], IGNORE_MULTIPLE);
        $personalcard = $this->create_card(
            $this->personalboardid,
            $personalcolumnid,
            $this->targetuser->id,
            'Personal card'
        );
        $this->personalcardid = $personalcard->id;
        $DB->insert_record('kanban_discussion_comment', [
            'kanban_card' => $personalcard->id,
            'userid' => $this->otheruser->id,
            'content' => 'Personal board comment',
            'timecreated' => time(),
        ]);
        get_file_storage()->create_file_from_string([
            'contextid' => $this->context->id,
            'component' => 'mod_kanban',
            'filearea' => 'attachments',
            'itemid' => $personalcard->id,
            'filepath' => '/',
            'filename' => 'personal.txt',
        ], 'Personal attachment');

        helper::add_or_update_calendar_event(
            $this->kanban,
            $this->sharedcard,
            [$this->targetuser->id, $this->otheruser->id]
        );
    }

    /**
     * Create a complete card record.
     *
     * @param int $boardid Board id.
     * @param int $columnid Column id.
     * @param int $userid Creator id.
     * @param string $title Card title.
     * @return \stdClass Card.
     */
    private function create_card(int $boardid, int $columnid, int $userid, string $title): \stdClass {
        global $DB;

        $now = time();
        $id = $DB->insert_record('kanban_card', [
            'title' => $title,
            'kanban_column' => $columnid,
            'kanban_board' => $boardid,
            'options' => '{}',
            'duedate' => $now + HOURSECS,
            'reminderdate' => null,
            'completed' => 0,
            'description' => 'Description for ' . $title,
            'descriptionformat' => FORMAT_HTML,
            'linkedactivity' => null,
            'originalid' => null,
            'discussion' => 1,
            'reminder_sent' => 0,
            'createdby' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
            'repeat_enable' => 0,
            'repeat_interval' => 1,
            'repeat_interval_type' => 0,
            'repeat_newduedate' => 0,
            'number' => random_int(1, 1000000),
        ]);
        return $DB->get_record('kanban_card', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Create a history record for the shared card.
     *
     * @param int $userid Actor id.
     * @param int $affecteduserid Affected user id.
     * @return int Record id.
     */
    private function create_history(int $userid, int $affecteduserid): int {
        global $DB;

        return $DB->insert_record('kanban_history', [
            'userid' => $userid,
            'kanban_board' => $this->sharedboard->id,
            'kanban_column' => $this->sharedcard->kanban_column,
            'kanban_card' => $this->sharedcard->id,
            'action' => 'privacy_test',
            'parameters' => '{}',
            'affected_userid' => $affecteduserid,
            'type' => 0,
            'timestamp' => time(),
        ]);
    }
    /**
     * Context discovery must cover every table that stores a user id.
     */
    public function test_get_contexts_for_each_relation(): void {
        global $DB;

        $creator = $this->getDataGenerator()->create_user();
        $assignee = $this->getDataGenerator()->create_user();
        $commenter = $this->getDataGenerator()->create_user();
        $actor = $this->getDataGenerator()->create_user();
        $affected = $this->getDataGenerator()->create_user();
        $personalowner = $this->getDataGenerator()->create_user();
        $unrelated = $this->getDataGenerator()->create_user();

        $card = $this->create_card(
            $this->sharedboard->id,
            $this->sharedcard->kanban_column,
            $creator->id,
            'Relations card'
        );
        $DB->insert_record('kanban_assignee', ['kanban_card' => $card->id, 'userid' => $assignee->id]);
        $DB->insert_record('kanban_discussion_comment', [
            'kanban_card' => $card->id,
            'userid' => $commenter->id,
            'content' => 'Relations comment',
            'timecreated' => time(),
        ]);
        $DB->insert_record('kanban_history', [
            'userid' => $actor->id,
            'kanban_board' => $this->sharedboard->id,
            'kanban_column' => $card->kanban_column,
            'kanban_card' => $card->id,
            'action' => 'relations_test',
            'parameters' => '{}',
            'affected_userid' => $affected->id,
            'type' => 0,
            'timestamp' => time(),
        ]);
        $manager = new boardmanager($this->kanban->cmid);
        $manager->get_or_create_board($personalowner->id);

        foreach ([$creator, $assignee, $commenter, $actor, $affected, $personalowner] as $user) {
            $contexts = provider::get_contexts_for_userid($user->id)->get_contextids();
            $this->assertEquals([$this->context->id], array_values($contexts));
        }
        $this->assertEmpty(provider::get_contexts_for_userid($unrelated->id)->get_contextids());
    }

    /**
     * Users in context must include authors, assignees, commenters, and history users.
     */
    public function test_get_users_in_context(): void {
        global $DB;

        $this->assertTrue($DB->record_exists('kanban_board', [
            'id' => $this->personalboardid,
            'userid' => $this->targetuser->id,
        ]));
        $this->assertTrue($DB->record_exists('kanban_card', [
            'id' => $this->sharedcard->id,
            'createdby' => $this->targetuser->id,
        ]));
        $this->assertTrue($DB->record_exists('kanban_assignee', [
            'kanban_card' => $this->sharedcard->id,
            'userid' => $this->targetuser->id,
        ]));
        $this->assertTrue($DB->record_exists('kanban_discussion_comment', [
            'kanban_card' => $this->sharedcard->id,
            'userid' => $this->targetuser->id,
        ]));

        $userlist = new userlist($this->context, 'mod_kanban');
        provider::get_users_in_context($userlist);
        $userids = $userlist->get_userids();
        $message = 'Discovered user IDs: ' . implode(', ', $userids);

        $this->assertContains($this->targetuser->id, $userids, $message);
        $this->assertContains($this->otheruser->id, $userids, $message);
        $this->assertNotContains(0, $userids);
    }

    /**
     * Export must produce all categories without SQL errors or overwrites.
     */
    public function test_export_user_data(): void {
        $this->export_context_data_for_user($this->targetuser->id, $this->context, 'mod_kanban');
        $writer = writer::with_context($this->context);

        $this->assertTrue($writer->has_any_data());
        $this->assertNotEmpty($writer->get_data(['created_cards']));
        $this->assertNotEmpty($writer->get_data(['assigned_cards']));
        $this->assertNotEmpty($writer->get_data(['discussion_comments']));
        $this->assertNotEmpty($writer->get_data(['history']));
        $this->assertNotEmpty($writer->get_data(['personal_boards']));
    }

    /**
     * Single-user deletion must remove only the approved user's data.
     */
    public function test_delete_data_for_user(): void {
        $approved = new approved_contextlist(
            \core_user::get_user($this->targetuser->id),
            'mod_kanban',
            [$this->context->id]
        );
        provider::delete_data_for_user($approved);
        $this->assert_target_user_deleted();
    }

    /**
     * Batch deletion must use the same safe semantics as single-user deletion.
     */
    public function test_delete_data_for_users(): void {
        $approved = new approved_userlist($this->context, 'mod_kanban', [$this->targetuser->id]);
        provider::delete_data_for_users($approved);
        $this->assert_target_user_deleted();
    }

    /**
     * Deleting all user data must not affect another Kanban context.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $otherkanban = $this->getDataGenerator()->create_module('kanban', ['course' => $this->kanban->course]);
        $otherboard = $DB->get_record('kanban_board', [
            'kanban_instance' => $otherkanban->id,
            'userid' => 0,
            'groupid' => 0,
            'template' => 0,
        ], '*', MUST_EXIST);
        $othercolumnid = $DB->get_field('kanban_column', 'id', [
            'kanban_board' => $otherboard->id,
        ], IGNORE_MULTIPLE);
        $othercard = $this->create_card($otherboard->id, $othercolumnid, $this->otheruser->id, 'Other context');

        provider::delete_data_for_all_users_in_context($this->context);

        $this->assertEquals(0, $DB->count_records('kanban_card', [
            'kanban_board' => $this->sharedboard->id,
        ]));
        $this->assertEquals(0, $DB->count_records('kanban_board', [
            'kanban_instance' => $this->kanban->id,
            'template' => 0,
        ]));
        $this->assertTrue($DB->record_exists('kanban_card', ['id' => $othercard->id]));
        $this->assertTrue($DB->record_exists('kanban_board', ['id' => $otherboard->id]));
    }

    /**
     * Assert the postconditions shared by both user-deletion entry points.
     */
    private function assert_target_user_deleted(): void {
        global $DB;

        $sharedcard = $DB->get_record('kanban_card', ['id' => $this->sharedcard->id], '*', MUST_EXIST);
        $this->assertEquals(0, $sharedcard->createdby);
        $this->assertFalse($DB->record_exists('kanban_assignee', [
            'kanban_card' => $this->sharedcard->id,
            'userid' => $this->targetuser->id,
        ]));
        $this->assertTrue($DB->record_exists('kanban_assignee', [
            'kanban_card' => $this->sharedcard->id,
            'userid' => $this->otheruser->id,
        ]));
        $this->assertFalse($DB->record_exists('kanban_discussion_comment', [
            'kanban_card' => $this->sharedcard->id,
            'userid' => $this->targetuser->id,
        ]));
        $this->assertTrue($DB->record_exists('kanban_discussion_comment', [
            'kanban_card' => $this->sharedcard->id,
            'userid' => $this->otheruser->id,
        ]));
        $this->assertFalse($DB->record_exists('kanban_history', ['id' => $this->targethistoryid]));
        $affectedhistory = $DB->get_record(
            'kanban_history',
            ['id' => $this->affectedhistoryid],
            '*',
            MUST_EXIST
        );
        $this->assertEquals(0, $affectedhistory->affected_userid);
        $this->assertFalse($DB->record_exists('kanban_board', ['id' => $this->personalboardid]));
        $this->assertFalse($DB->record_exists('kanban_card', ['id' => $this->personalcardid]));
        $this->assertFalse(get_file_storage()->file_exists(
            $this->context->id,
            'mod_kanban',
            'attachments',
            $this->personalcardid,
            '/',
            'personal.txt'
        ));
        $this->assertEquals(0, $DB->count_records('event', [
            'modulename' => 'kanban',
            'instance' => $this->kanban->id,
            'userid' => $this->targetuser->id,
        ]));
        $this->assertGreaterThan(0, $DB->count_records('event', [
            'modulename' => 'kanban',
            'instance' => $this->kanban->id,
            'userid' => $this->otheruser->id,
        ]));
    }
}
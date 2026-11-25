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

/**
 * Synthetic media erasure, export, discovery and retention regressions.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 TAG Digital Solutions Ltd <https://tandreig.com>
 * @author     Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use quizaccess_tagproctoring\privacy\provider;

/**
 * Run only in Moodle's dedicated PHPUnit database and dataroot.
 *
 * @covers \quizaccess_tagproctoring\privacy\provider
 * @covers \quizaccess_tagproctoring\task\cleanup_files
 */
final class privacy_test extends \core_privacy\tests\provider_testcase {
    /**
     * Seed two synthetic users, two quizzes, all four file areas and review rows.
     *
     * @return array User, other user, first quiz, second quiz, teacher.
     */
    private function fixture(): array {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quizzes = [];
        for ($i = 0; $i < 2; $i++) {
            $quizzes[] = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        }
        $users = [$this->getDataGenerator()->create_user(), $this->getDataGenerator()->create_user()];
        $teacher = $this->getDataGenerator()->create_user();
        foreach ($quizzes as $quiz) {
            $context = \context_module::instance($quiz->cmid);
            foreach ($users as $user) {
                // A distinct synthetic question usage satisfies quiz_attempts' unique key.
                $usage = \question_engine::make_questions_usage_by_activity('mod_quiz', $context);
                $usage->set_preferred_behaviour('deferredfeedback');
                \question_engine::save_questions_usage_by_activity($usage);
                $attemptid = $DB->insert_record('quiz_attempts', (object) [
                    'quiz' => $quiz->id, 'userid' => $user->id, 'attempt' => 1,
                    'uniqueid' => $usage->get_id(), 'layout' => '', 'state' => 'finished',
                    'timestart' => time() - 40 * DAYSECS, 'timefinish' => time() - 39 * DAYSECS,
                ]);
                $DB->insert_record('quizaccess_tagproctoring_overrides', (object) [
                    'attemptid' => $attemptid, 'valid_override' => 1, 'reviewed' => 1,
                    'overriddenby' => $teacher->id, 'reviewedby' => $teacher->id, 'timecreated' => time(),
                ]);
                $DB->insert_record('quizaccess_tagproctoring_time', (object) [
                    'quizid' => $quiz->id, 'userid' => $user->id, 'attemptid' => $attemptid, 'timecreated' => time(),
                ]);
                foreach (['roomscan', 'selfie', 'idphoto', 'screenshots'] as $area) {
                    get_file_storage()->create_file_from_string([
                        'contextid' => $context->id, 'component' => 'quizaccess_tagproctoring',
                        'filearea' => $area, 'itemid' => $user->id, 'filepath' => '/',
                        'filename' => $area . '.jpg',
                    ], 'Synthetic fixture bytes only');
                }
                $DB->insert_record('quizaccess_tagproctoring_scan', (object) [
                    'quizid' => $quiz->id, 'userid' => $user->id, 'attemptid' => $attemptid,
                    'timecreated' => time(), 'filename' => 'roomscan.jpg',
                    'selfie_filename' => 'selfie.jpg', 'idphoto_filename' => 'idphoto.jpg',
                ]);
                $DB->insert_record('quizaccess_tagproctoring_screenshots', (object) [
                    'quizid' => $quiz->id, 'userid' => $user->id, 'attemptid' => $attemptid,
                    'timecreated' => time(), 'filename' => 'screenshots.jpg',
                ]);
            }
        }
        return [$users[0], $users[1], $quizzes[0], $quizzes[1], $teacher];
    }

    /**
     * Assert whether all four media areas remain for a user in a quiz.
     *
     * @param object $quiz Quiz fixture.
     * @param int $userid Data subject.
     * @param bool $present Expected media presence.
     */
    private function assert_media(object $quiz, int $userid, bool $present): void {
        $context = \context_module::instance($quiz->cmid);
        foreach (['roomscan', 'selfie', 'idphoto', 'screenshots'] as $area) {
            $files = get_file_storage()->get_area_files(
                $context->id,
                'quizaccess_tagproctoring',
                $area,
                $userid,
                'id',
                false
            );
            $this->assertSame($present, !empty($files), $area);
        }
    }

    /**
     * Erasure is scoped to approved context and user, including orphaned media.
     */
    public function test_delete_user_preserves_other_user_and_context(): void {
        global $DB;
        [$user, $other, $quiz, $second] = $this->fixture();
        $context = \context_module::instance($quiz->cmid);
        provider::delete_data_for_user(new approved_contextlist($user, 'quizaccess_tagproctoring', [$context->id]));
        $this->assert_media($quiz, $user->id, false);
        $this->assert_media($quiz, $other->id, true);
        $this->assert_media($second, $user->id, true);
        $this->assertFalse($DB->record_exists('quizaccess_tagproctoring_time', ['quizid' => $quiz->id, 'userid' => $user->id]));
        $attemptid = $DB->get_field('quiz_attempts', 'id', ['quiz' => $quiz->id, 'userid' => $user->id]);
        $this->assertFalse($DB->record_exists('quizaccess_tagproctoring_overrides', ['attemptid' => $attemptid]));
    }

    /**
     * Bulk erasure follows the approved user list.
     */
    public function test_bulk_delete(): void {
        [$user, $other, $quiz, $second] = $this->fixture();
        $context = \context_module::instance($quiz->cmid);
        provider::delete_data_for_users(new approved_userlist($context, 'quizaccess_tagproctoring', [$user->id]));
        $this->assert_media($quiz, $user->id, false);
        $this->assert_media($quiz, $other->id, true);
        $this->assert_media($second, $user->id, true);
    }

    /**
     * Context erasure includes all media and verdicts, leaving other quizzes alone.
     */
    public function test_delete_context(): void {
        global $DB;
        [$user, $other, $quiz, $second] = $this->fixture();
        provider::delete_data_for_all_users_in_context(\context_module::instance($quiz->cmid));
        $this->assert_media($quiz, $user->id, false);
        $this->assert_media($quiz, $other->id, false);
        $this->assert_media($second, $user->id, true);
        $this->assertEquals(2, $DB->count_records('quizaccess_tagproctoring_overrides'));
    }

    /**
     * Export includes all stored evidence and review records.
     */
    public function test_export_includes_media(): void {
        [$user, $other, $quiz] = $this->fixture();
        $context = \context_module::instance($quiz->cmid);
        provider::export_user_data(new approved_contextlist($user, 'quizaccess_tagproctoring', [$context->id]));
        $writer = writer::with_context($context);
        foreach (['roomscan', 'selfie', 'idphoto', 'screenshots'] as $area) {
            $files = $writer->get_files([get_string('privacy:path:media', 'quizaccess_tagproctoring'), $area]);
            $this->assertNotEmpty($files, $area);
        }
        $data = $writer->get_data([get_string('privacy:path:reviews', 'quizaccess_tagproctoring')]);
        $this->assertCount(1, $data->reviews);
        $this->assertCount(1, $data->timer_compensations);
    }

    /**
     * An override alone discovers both student and reviewer without module collisions.
     */
    public function test_discovery_includes_override_only_records(): void {
        global $DB;
        [$user, $other, $quiz, $second, $teacher] = $this->fixture();
        foreach (['scan', 'screenshots', 'events', 'time'] as $table) {
            $DB->delete_records('quizaccess_tagproctoring_' . $table);
        }
        foreach ([$quiz, $second] as $item) {
            get_file_storage()->delete_area_files(\context_module::instance($item->cmid)->id, 'quizaccess_tagproctoring');
        }
        // Give a page the same instance ID as the quiz to exercise the module join.
        $page = $this->getDataGenerator()->create_module('page', ['course' => $quiz->course]);
        $DB->set_field('course_modules', 'instance', $quiz->id, ['id' => $page->cmid]);
        $contexts = provider::get_contexts_for_userid($user->id)->get_contextids();
        $this->assertCount(2, $contexts);
        $this->assertNotContainsEquals(\context_module::instance($page->cmid)->id, $contexts);
        $context = \context_module::instance($quiz->cmid);
        $list = new userlist($context, 'quizaccess_tagproctoring');
        provider::get_users_in_context($list);
        $this->assertContainsEquals($user->id, $list->get_userids());
        $this->assertContainsEquals($teacher->id, $list->get_userids());
        provider::delete_data_for_user(new approved_contextlist($teacher, 'quizaccess_tagproctoring', [$context->id]));
        $this->assertEquals(4, $DB->count_records('quizaccess_tagproctoring_overrides'));
        $this->assertFalse($DB->record_exists('quizaccess_tagproctoring_overrides', [
            'overriddenby' => $teacher->id,
            'attemptid' => $DB->get_field('quiz_attempts', 'id', ['quiz' => $quiz->id, 'userid' => $user->id]),
        ]));
    }

    /**
     * Retention removes one old screenshot without deleting a newer file in the same area.
     */
    public function test_retention_preserves_other_screenshots(): void {
        global $DB;
        [$user, $other, $quiz] = $this->fixture();
        $context = \context_module::instance($quiz->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'quizaccess_tagproctoring',
            'filearea' => 'screenshots', 'itemid' => $user->id, 'filepath' => '/', 'filename' => 'new.jpg',
        ], 'New synthetic screenshot');
        // New media from a newer attempt must not be removed by the old attempt's cleanup.
        set_config('retentiondays', 30, 'quizaccess_tagproctoring');
        ob_start();
        try {
            (new \quizaccess_tagproctoring\task\cleanup_files())->execute();
        } finally {
            ob_end_clean();
        }
        $this->assertFalse(get_file_storage()->get_file(
            $context->id,
            'quizaccess_tagproctoring',
            'screenshots',
            $user->id,
            '/',
            'screenshots.jpg'
        ));
        $this->assertNotFalse(get_file_storage()->get_file(
            $context->id,
            'quizaccess_tagproctoring',
            'screenshots',
            $user->id,
            '/',
            'new.jpg'
        ));
        $this->assertEquals(0, $DB->count_records('quizaccess_tagproctoring_screenshots'));
    }
}

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
 * Content and upload size regressions.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring;

use quizaccess_tagproctoring\task\cleanup_files;

/**
 * Tests for the scheduled cleanup task.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \quizaccess_tagproctoring\task\cleanup_files
 */
final class cleanup_files_test extends \advanced_testcase {
    /**
     * Screenshot rows/files and events whose quiz attempt no longer exists are removed
     * even when they are newer than the retention period.
     */
    public function test_orphans_of_deleted_attempts_are_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('retentiondays', 30, 'quizaccess_tagproctoring');

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user();
        $context = \context_module::instance($quiz->cmid);
        $fs = get_file_storage();

        $missingattempt = 987654321;
        $fs->create_file_from_string([
            'contextid' => $context->id, 'component' => 'quizaccess_tagproctoring', 'filearea' => 'screenshots',
            'itemid' => $user->id, 'filepath' => '/', 'filename' => 'orphan.jpg',
        ], 'synthetic');
        $DB->insert_record('quizaccess_tagproctoring_screenshots', (object) [
            'quizid' => $quiz->id, 'userid' => $user->id, 'attemptid' => $missingattempt,
            'timecreated' => time(), 'filename' => 'orphan.jpg', 'source' => 'tabswitch',
        ]);
        $DB->insert_record('quizaccess_tagproctoring_events', (object) [
            'quizid' => $quiz->id, 'userid' => $user->id, 'attemptid' => $missingattempt,
            'timecreated' => time(), 'eventtype' => 'tabswitch', 'detail' => '',
        ]);
        // A fresh scan record without an attempt (student is still before the quiz) must survive.
        $scanid = $DB->insert_record('quizaccess_tagproctoring_scan', (object) [
            'quizid' => $quiz->id, 'userid' => $user->id, 'attemptid' => 0, 'timecreated' => time(),
            'filename' => 'roomscan.webm',
        ]);

        ob_start();
        (new cleanup_files())->execute();
        ob_end_clean();

        $this->assertSame(0, $DB->count_records('quizaccess_tagproctoring_screenshots'));
        $this->assertSame(0, $DB->count_records('quizaccess_tagproctoring_events'));
        $this->assertFalse($fs->file_exists($context->id, 'quizaccess_tagproctoring', 'screenshots', $user->id, '/', 'orphan.jpg'));
        $this->assertTrue($DB->record_exists('quizaccess_tagproctoring_scan', ['id' => $scanid]));
    }
}

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
 * Event observer regressions.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring;

/**
 * Tests for the event observers.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \quizaccess_tagproctoring\local\observer
 */
final class observer_test extends \advanced_testcase {
    /**
     * Deleting a student's attempt flags their room scan for a new readiness check,
     * without touching other students or the stored evidence.
     */
    /**
     * Starting an attempt links the evidence to it and consumes a granted new check.
     */
    public function test_attempt_started_locks_the_evidence(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $DB->insert_record('quizaccess_tagproctoring_scan', (object) [
            'quizid' => $quiz->id, 'userid' => $student->id, 'attemptid' => 0, 'timecreated' => time(),
            'filename' => 'roomscan.webm', 'selfie_filename' => 'selfie.jpg', 'idphoto_filename' => 'id.jpg',
            'needs_rescan' => 1,
        ]);
        // Like mod_quiz, do not pass the quiz id in the event data: the observer reads it from the attempt.
        $attemptid = $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quiz->id, 'userid' => $student->id, 'attempt' => 1, 'uniqueid' => 900042, 'layout' => '',
            'currentpage' => 0, 'preview' => 0, 'state' => 'inprogress', 'timestart' => time(), 'timefinish' => 0,
            'timemodified' => time(), 'timemodifiedoffline' => 0,
        ]);
        $event = \mod_quiz\event\attempt_started::create([
            'objectid' => $attemptid,
            'relateduserid' => $student->id,
            'courseid' => $course->id,
            'context' => \context_module::instance($quiz->cmid),
        ]);
        $event->trigger();
        $scan = $DB->get_record('quizaccess_tagproctoring_scan', ['quizid' => $quiz->id, 'userid' => $student->id]);
        $this->assertSame((int) $attemptid, (int) $scan->attemptid);
        $this->assertSame(0, (int) $scan->needs_rescan);
        $this->assertFalse(\quizaccess_tagproctoring\local\scan_record::replacement_allowed($quiz->id, $student->id));
    }

    public function test_attempt_deleted_requires_a_new_scan(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        foreach ([$student, $other] as $user) {
            $DB->insert_record('quizaccess_tagproctoring_scan', (object) [
                'quizid' => $quiz->id, 'userid' => $user->id, 'attemptid' => 0, 'timecreated' => time(),
                'filename' => 'roomscan.webm', 'selfie_filename' => 'selfie.jpg', 'idphoto_filename' => 'id.jpg',
                'needs_rescan' => 0,
            ]);
        }

        $event = \mod_quiz\event\attempt_deleted::create([
            'objectid' => 123456,
            'relateduserid' => $student->id,
            'context' => \context_module::instance($quiz->cmid),
            'other' => ['quizid' => $quiz->id],
        ]);
        $event->trigger();

        $scan = $DB->get_record('quizaccess_tagproctoring_scan', ['quizid' => $quiz->id, 'userid' => $student->id]);
        $this->assertSame(1, (int) $scan->needs_rescan);
        $this->assertSame('roomscan.webm', $scan->filename, 'Previous evidence is kept until the new scan.');
        $otherscan = $DB->get_record('quizaccess_tagproctoring_scan', ['quizid' => $quiz->id, 'userid' => $other->id]);
        $this->assertSame(0, (int) $otherscan->needs_rescan);
    }
}

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
 * Student evidence and teacher review separation regressions.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring;

use quizaccess_tagproctoring\local\scan_record;

/**
 * Persisted review state must not be controlled by student upload parameters.
 *
 * @covers \quizaccess_tagproctoring\local\scan_record
 */
final class scan_record_test extends \advanced_testcase {
    /**
     * New evidence invalidates the old verdict, irrespective of client claims.
     */
    public function test_student_cannot_verify_identity(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $post = $_POST;
        $_POST['face_match_verified'] = 1;
        $_POST['face_match_distance'] = 0.01;
        try {
            scan_record::save_image($quiz->id, $user->id, 'idphoto', 'first.jpg');
            $scan = $DB->get_record('quizaccess_tagproctoring_scan', ['quizid' => $quiz->id, 'userid' => $user->id]);
            $this->assertNull($scan->face_match_verified);
            foreach (['selfie', 'idphoto'] as $area) {
                $DB->set_field('quizaccess_tagproctoring_scan', 'face_match_verified', 1, ['id' => $scan->id]);
                scan_record::save_image($quiz->id, $user->id, $area, 'replacement.jpg');
                $scan = $DB->get_record('quizaccess_tagproctoring_scan', ['id' => $scan->id]);
                $this->assertNull($scan->face_match_verified);
                $this->assertNull($scan->face_match_distance);
                $this->assertSame('replacement.jpg', $scan->{$area . '_filename'});
            }
        } finally {
            $_POST = $post;
        }
    }

    /**
     * Clearing valid override preserves reviewed, author and timestamps exactly.
     */
    /**
     * Evidence can be (re)recorded only while the readiness check is required.
     */
    public function test_replacement_allowed_only_while_check_required(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', ['course' => $course->id]);
        $DB->delete_records('quizaccess_tagproctoring_settings', ['quizid' => $quiz->id]);
        $DB->insert_record('quizaccess_tagproctoring_settings', (object) [
            'quizid' => $quiz->id, 'enabled' => 1, 'enableroomscan' => 1, 'durationsec' => 45, 'rescanperattempt' => 0,
        ]);
        // No record yet.
        $this->assertTrue(scan_record::replacement_allowed($quiz->id, $user->id));
        // Incomplete record (selfie only).
        scan_record::save_image($quiz->id, $user->id, 'selfie', 'selfie.jpg');
        $this->assertTrue(scan_record::replacement_allowed($quiz->id, $user->id));
        scan_record::save_image($quiz->id, $user->id, 'idphoto', 'id.jpg');
        $this->assertTrue(scan_record::replacement_allowed($quiz->id, $user->id), 'room scan still missing');
        $DB->set_field('quizaccess_tagproctoring_scan', 'filename', 'scan.webm', ['quizid' => $quiz->id, 'userid' => $user->id]);
        // Complete: locked.
        $this->assertFalse(scan_record::replacement_allowed($quiz->id, $user->id));
        // Teacher grants a new check: allowed until the attempt starts.
        $DB->set_field('quizaccess_tagproctoring_scan', 'needs_rescan', 1, ['quizid' => $quiz->id, 'userid' => $user->id]);
        $this->assertTrue(scan_record::replacement_allowed($quiz->id, $user->id));
        scan_record::mark_used($quiz->id, $user->id, 77);
        $scan = $DB->get_record('quizaccess_tagproctoring_scan', ['quizid' => $quiz->id, 'userid' => $user->id]);
        $this->assertSame(0, (int) $scan->needs_rescan);
        $this->assertSame(77, (int) $scan->attemptid);
        $this->assertFalse(scan_record::replacement_allowed($quiz->id, $user->id));
        // Room scan not required by the quiz: selfie + ID make the check complete.
        $DB->set_field('quizaccess_tagproctoring_settings', 'enableroomscan', 0, ['quizid' => $quiz->id]);
        $DB->set_field('quizaccess_tagproctoring_scan', 'filename', '', ['quizid' => $quiz->id, 'userid' => $user->id]);
        $this->assertFalse(scan_record::replacement_allowed($quiz->id, $user->id));
        // New room scan before every attempt: allowed between attempts, not while one is in progress.
        $DB->set_field('quizaccess_tagproctoring_settings', 'rescanperattempt', 1, ['quizid' => $quiz->id]);
        $this->assertTrue(scan_record::replacement_allowed($quiz->id, $user->id));
        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quiz->id, 'userid' => $user->id, 'attempt' => 1, 'uniqueid' => 900001, 'layout' => '',
            'currentpage' => 0, 'preview' => 0, 'state' => 'inprogress', 'timestart' => time(), 'timefinish' => 0,
            'timemodified' => time(), 'timemodifiedoffline' => 0,
        ]);
        $this->assertFalse(scan_record::replacement_allowed($quiz->id, $user->id));
        $DB->set_field('quiz_attempts', 'state', 'finished', ['uniqueid' => 900001]);
        $this->assertTrue(scan_record::replacement_allowed($quiz->id, $user->id));
    }

    public function test_override_clear_preserves_review(): void {
        global $DB;
        $this->resetAfterTest();
        $id = $DB->insert_record('quizaccess_tagproctoring_overrides', (object) [
            'attemptid' => 123, 'valid_override' => 1, 'overriddenby' => 234, 'timecreated' => 123456,
            'reviewed' => 1, 'reviewedby' => 345, 'timereviewed' => 234567,
        ]);
        $before = $DB->get_record('quizaccess_tagproctoring_overrides', ['id' => $id]);
        scan_record::clear_override($id);
        $after = $DB->get_record('quizaccess_tagproctoring_overrides', ['id' => $id]);
        $this->assertEquals(0, $after->valid_override);
        foreach (['reviewed', 'reviewedby', 'timereviewed', 'overriddenby', 'timecreated'] as $field) {
            $this->assertSame($before->$field, $after->$field, $field);
        }
    }
}

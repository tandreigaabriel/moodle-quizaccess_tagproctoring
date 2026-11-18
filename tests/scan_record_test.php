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

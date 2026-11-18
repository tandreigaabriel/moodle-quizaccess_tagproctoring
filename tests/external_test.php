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
 * Attempt ownership, state and one-time compensation regressions.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring;

use quizaccess_tagproctoring\external\log_event;
use quizaccess_tagproctoring\external\reset_timer;

/**
 * These tests require a separately initialised synthetic PHPUnit database.
 *
 * @covers \quizaccess_tagproctoring\external\log_event
 * @covers \quizaccess_tagproctoring\external\reset_timer
 */
final class external_test extends \advanced_testcase {
    /**
     * Build a complete synthetic quiz attempt using Moodle's generators.
     *
     * @return array Student, quiz and attempt.
     */
    private function fixture(): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id, 'timelimit' => 600, 'sumgrades' => 1,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $generator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $category = $generator->create_question_category();
        $question = $generator->create_question('truefalse', null, ['category' => $category->id]);
        quiz_add_quiz_question($question->id, $quiz);
        $this->setUser($student);
        $attempt = $this->getDataGenerator()->get_plugin_generator('mod_quiz')->create_attempt($quiz->id, $student->id);
        $DB->set_field('quiz_attempts', 'timestart', time() - 120, ['id' => $attempt->id]);
        $DB->delete_records('quizaccess_tagproctoring_settings', ['quizid' => $quiz->id]);
        $DB->insert_record('quizaccess_tagproctoring_settings', (object) [
            'quizid' => $quiz->id, 'enabled' => 1, 'enableroomscan' => 1, 'durationsec' => 45,
        ]);
        $DB->insert_record('quizaccess_tagproctoring_scan', (object) [
            'quizid' => $quiz->id, 'userid' => $student->id, 'attemptid' => 0,
            'timecreated' => time(), 'filename' => 'synthetic.webm',
            'selfie_filename' => 'synthetic.jpg', 'idphoto_filename' => 'synthetic-id.jpg',
        ]);
        set_config('enableroomscan', 1, 'quizaccess_tagproctoring');
        return [$student, $quiz, $attempt];
    }

    /**
     * Both external APIs reject an owned attempt paired with another quiz.
     */
    public function test_foreign_quiz_attempt(): void {
        global $DB;
        [$student, $quiz, $attempt] = $this->fixture();
        $other = $this->getDataGenerator()->create_module('quiz', ['course' => $quiz->course]);
        foreach (['log', 'reset'] as $action) {
            try {
                if ($action === 'log') {
                    log_event::execute($other->cmid, $attempt->id, 'tabswitch', 'synthetic');
                } else {
                    reset_timer::execute($other->cmid, $attempt->id);
                }
                $this->fail('Cross-quiz attempt was accepted.');
            } catch (\dml_missing_record_exception $e) {
                $this->assertFalse($DB->record_exists('quizaccess_tagproctoring_events', ['attemptid' => $attempt->id]));
            }
        }
    }

    /**
     * Finished, overdue and abandoned attempts cannot log new events.
     */
    public function test_terminal_attempt_states(): void {
        global $DB;
        [$student, $quiz, $attempt] = $this->fixture();
        foreach (['finished', 'overdue', 'abandoned'] as $state) {
            $DB->set_field('quiz_attempts', 'state', $state, ['id' => $attempt->id]);
            foreach (['log', 'reset'] as $action) {
                try {
                    if ($action === 'log') {
                        log_event::execute($quiz->cmid, $attempt->id, 'tabswitch');
                    } else {
                        reset_timer::execute($quiz->cmid, $attempt->id);
                    }
                    $this->fail('Inactive attempt was accepted.');
                } catch (\dml_missing_record_exception $e) {
                    $this->assertFalse($DB->record_exists('quizaccess_tagproctoring_time', ['attemptid' => $attempt->id]));
                }
            }
        }
    }

    /**
     * The marker persists after event deletion and after another thirty seconds.
     */
    public function test_timer_is_compensated_once(): void {
        global $DB;
        [$student, $quiz, $attempt] = $this->fixture();
        $result = reset_timer::execute($quiz->cmid, $attempt->id);
        $this->assertGreaterThanOrEqual(120, $result['added_seconds']);
        $after = $DB->get_record('quiz_attempts', ['id' => $attempt->id]);
        $this->assertEquals($after->timestart + 600, $after->timecheckstate);
        $DB->delete_records('quizaccess_tagproctoring_events', ['attemptid' => $attempt->id]);
        $DB->set_field('quiz_attempts', 'timestart', time() - 60, ['id' => $attempt->id]);
        $this->assertSame(0, reset_timer::execute($quiz->cmid, $attempt->id)['added_seconds']);
        $this->assertEquals(1, $DB->count_records('quizaccess_tagproctoring_time', ['attemptid' => $attempt->id]));
    }

    /**
     * A short wizard consumes its grant too; later calls cannot accumulate time.
     */
    public function test_short_wizard_is_not_a_delayed_grant(): void {
        global $DB;
        [$student, $quiz, $attempt] = $this->fixture();
        $DB->set_field('quiz_attempts', 'timestart', time() - 5, ['id' => $attempt->id]);
        $this->assertSame(0, reset_timer::execute($quiz->cmid, $attempt->id)['added_seconds']);
        $DB->set_field('quiz_attempts', 'timestart', time() - 60, ['id' => $attempt->id]);
        $this->assertSame(0, reset_timer::execute($quiz->cmid, $attempt->id)['added_seconds']);
    }
}

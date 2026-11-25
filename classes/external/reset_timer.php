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
 * External function: reset quiz attempt timer after the pre-exam wizard completes.
 *
 * Moves quiz_attempts.timestart to now so students do not lose quiz time
 * while completing the proctoring pre-exam checks (room scan, selfie, ID, screen share).
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 TAG Digital Solutions Ltd <https://tandreig.com>
 * @author     Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use context_module;

/**
 * Resets the quiz attempt start time to now so the student's timer is not eaten by the wizard.
 */
class reset_timer extends external_api {
    /**
     * Describe the parameters accepted by reset_timer.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'      => new external_value(PARAM_INT, 'Course-module ID'),
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt ID'),
        ]);
    }

    /**
     * Reset timestart on the quiz attempt to now.
     *
     * Only resets if the attempt belongs to the current user, is still in progress,
     * and the quiz has a time limit (otherwise there is nothing to gain).
     *
     * @param  int   $cmid      Course-module ID.
     * @param  int   $attemptid Quiz attempt ID.
     * @return array ['status' => 'ok', 'added_seconds' => int]
     */
    public static function execute(int $cmid, int $attemptid): array {
        global $DB, $USER, $CFG;

        [
            'cmid'      => $cmid,
            'attemptid' => $attemptid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'cmid'      => $cmid,
            'attemptid' => $attemptid,
        ]);

        // Resolve context and check capability.
        $cm      = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id, MUST_EXIST);
        self::validate_context($context);
        require_capability('mod/quiz:attempt', $context);

        // Serialize checks and the compensation marker, including concurrent requests.
        $factory = \core\lock\lock_config::get_lock_factory('quizaccess_tagproctoring');
        $lock = $factory->get_lock('timer_' . $attemptid, 10);
        if (!$lock) {
            throw new \moodle_exception('error_general', 'quizaccess_tagproctoring');
        }
        try {
            $attempt = $DB->get_record('quiz_attempts', [
                'id' => $attemptid,
                'userid' => $USER->id,
                'quiz' => $cm->instance,
                'state' => 'inprogress',
            ], '*', MUST_EXIST);
            $quiz = $DB->get_record('quiz', ['id' => $attempt->quiz], '*', MUST_EXIST);
            $settings = $DB->get_record('quizaccess_tagproctoring_settings', ['quizid' => $quiz->id]);
            $scan = $DB->get_record('quizaccess_tagproctoring_scan', [
                'quizid' => $quiz->id, 'userid' => $USER->id,
            ]);
            $now = time();
            $elapsed = max(0, $now - (int) $attempt->timestart);
            $roomrequired = !$settings || !isset($settings->enableroomscan) || $settings->enableroomscan;
            if (
                !$settings || empty($settings->enabled) || !$scan || !empty($scan->needs_rescan)
                    || empty($scan->selfie_filename) || empty($scan->idphoto_filename)
                    || ($roomrequired && empty($scan->filename))
            ) {
                throw new \moodle_exception('invalidparameter', 'error');
            }
            if ($DB->record_exists('quizaccess_tagproctoring_time', ['attemptid' => $attemptid])) {
                return ['status' => 'ok', 'added_seconds' => 0];
            }
            $transaction = $DB->start_delegated_transaction();
            // Consume the one-time grant even when the wizard took less than 30 seconds.
            $DB->insert_record('quizaccess_tagproctoring_time', (object) [
                'attemptid' => $attemptid, 'userid' => $USER->id,
                'quizid' => $quiz->id, 'timecreated' => $now,
            ]);
            $added = 0;
            if (!empty($quiz->timelimit) && $elapsed > 30) {
                $DB->set_field('quiz_attempts', 'timestart', $now, ['id' => $attemptid]);
                $attemptobj = \mod_quiz\quiz_attempt::create($attemptid);
                $endtime = $attemptobj->get_access_manager($now)->get_end_time($attemptobj->get_attempt());
                $attemptobj->update_timecheckstate($endtime === false ? null : $endtime);
                $added = $elapsed;
            }
            $transaction->allow_commit();
            return ['status' => 'ok', 'added_seconds' => $added];
        } catch (\Throwable $e) {
            if (isset($transaction)) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Describe the return value of reset_timer.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'        => new external_value(PARAM_TEXT, 'ok'),
            'added_seconds' => new external_value(PARAM_INT, 'Seconds added back to the quiz timer'),
        ]);
    }
}

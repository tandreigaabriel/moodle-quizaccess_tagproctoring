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
 * Persist student evidence without accepting a teacher identity verdict.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 TAG Digital Solutions Ltd <https://tandreig.com>
 * @author     Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring\local;

/**
 * Scan image record updates shared by selfie and ID uploads.
 */
class scan_record {
    /**
     * Store an image reference and invalidate the old evidence's manual verdict.
     *
     * @param int $quizid Quiz ID.
     * @param int $userid Evidence owner.
     * @param string $area Either selfie or idphoto.
     * @param string $filename Validated, stored image filename.
     */
    public static function save_image(int $quizid, int $userid, string $area, string $filename): void {
        global $DB;
        if (!in_array($area, ['selfie', 'idphoto'], true)) {
            throw new \coding_exception('Unsupported image area');
        }
        $existing = $DB->get_record('quizaccess_tagproctoring_scan', ['quizid' => $quizid, 'userid' => $userid]);
        $record = (object) [
            $area . '_filename' => $filename,
            'face_match_verified' => null,
            'face_match_distance' => null,
        ];
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('quizaccess_tagproctoring_scan', $record);
        } else {
            $record->quizid = $quizid;
            $record->userid = $userid;
            $record->attemptid = 0;
            $record->timecreated = time();
            $record->filename = '';
            $record->selfie_filename = $record->selfie_filename ?? '';
            $record->idphoto_filename = $record->idphoto_filename ?? '';
            $DB->insert_record('quizaccess_tagproctoring_scan', $record);
        }
    }

    /**
     * Whether the student may record or replace readiness evidence (room scan, selfie, ID photo) now.
     *
     * Evidence can only be (re)recorded while the readiness check is required: no record yet, an
     * incomplete record, a teacher-granted new check (needs_rescan), or - when the quiz asks for a new
     * room scan before every attempt - while no attempt is in progress. A complete check that has been
     * used for an attempt is locked, so a student cannot overwrite flagged evidence afterwards.
     *
     * @param int $quizid Quiz id.
     * @param int $userid Student id.
     * @return bool
     */
    public static function replacement_allowed(int $quizid, int $userid): bool {
        global $DB;
        $scan = $DB->get_record('quizaccess_tagproctoring_scan', ['quizid' => $quizid, 'userid' => $userid]);
        if (!$scan) {
            return true;
        }
        if (!empty($scan->needs_rescan)) {
            return true;
        }
        $settings = $DB->get_record('quizaccess_tagproctoring_settings', ['quizid' => $quizid]);
        $roomscanrequired = ($settings && isset($settings->enableroomscan)) ? (bool) $settings->enableroomscan : true;
        $complete = (!$roomscanrequired || (string) $scan->filename !== '')
            && (string) $scan->selfie_filename !== '' && (string) $scan->idphoto_filename !== '';
        if (!$complete) {
            return true;
        }
        if ($settings && !empty($settings->rescanperattempt)) {
            // A fresh check is taken before every attempt: allowed until the next attempt starts.
            return !$DB->record_exists('quiz_attempts', ['quiz' => $quizid, 'userid' => $userid, 'state' => 'inprogress']);
        }
        return false;
    }

    /**
     * The readiness check has been used to start (or continue) an attempt: consume a teacher-granted
     * new check and remember the attempt the evidence belongs to.
     *
     * @param int $quizid Quiz id.
     * @param int $userid Student id.
     * @param int $attemptid Attempt id, 0 when the attempt is only about to be created.
     */
    public static function mark_used(int $quizid, int $userid, int $attemptid = 0): void {
        global $DB;
        $scan = $DB->get_record('quizaccess_tagproctoring_scan', ['quizid' => $quizid, 'userid' => $userid]);
        if (!$scan) {
            return;
        }
        $update = (object) ['id' => $scan->id, 'needs_rescan' => 0];
        if ($attemptid > 0) {
            $update->attemptid = $attemptid;
        }
        $DB->update_record('quizaccess_tagproctoring_scan', $update);
    }

    /**
     * Refuse the request with 403 unless the student may (re)record evidence now.
     *
     * @param int $quizid Quiz id.
     * @param int $userid Student id.
     */
    public static function require_replacement_allowed(int $quizid, int $userid): void {
        if (self::replacement_allowed($quizid, $userid)) {
            return;
        }
        ob_end_clean();
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => get_string('error_scan_locked', 'quizaccess_tagproctoring')]);
        exit;
    }

    /**
     * Clear only the manual risk override, preserving the independent review.
     *
     * @param int $recordid Existing override record ID.
     */
    public static function clear_override(int $recordid): void {
        global $DB;
        $DB->update_record('quizaccess_tagproctoring_overrides', (object) [
            'id' => $recordid,
            'valid_override' => 0,
        ]);
    }
}

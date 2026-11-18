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
 * @copyright  2026 Andrei Toma
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

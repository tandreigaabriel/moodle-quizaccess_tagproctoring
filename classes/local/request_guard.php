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
 * Request boundary validation.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring\local;

/**
 * Request and export guards.
 */
class request_guard {
    /**
     * Require a POST request before changing data.
     */
    public static function require_post(): void {
        // Method must be checked independently of parameter lookup's GET fallback.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new \moodle_exception('invalidparameter', 'error');
        }
    }

    /**
     * Read a required parameter from POST only.
     *
     * @param string $name Parameter name.
     * @param string $type Moodle parameter type.
     * @return mixed Cleaned value.
     */
    public static function post_param(string $name, string $type) {
        self::require_post();
        if (!isset($_POST[$name]) || is_array($_POST[$name])) {
            throw new \moodle_exception('invalidparameter', 'error');
        }
        return clean_param($_POST[$name], $type);
    }

    /**
     * Resolve the requested quiz, rejecting inconsistent routing IDs.
     *
     * @param int $quizid Authoritative quiz ID.
     * @param int $cmid Supplied course-module ID.
     * @param int|null $courseid Supplied course ID, if present.
     * @return \stdClass Actual quiz module.
     */
    public static function quiz_module(int $quizid, int $cmid, ?int $courseid = null): \stdClass {
        $cm = get_coursemodule_from_instance('quiz', $quizid, 0, false, MUST_EXIST);
        if ((int) $cm->id !== $cmid || ($courseid !== null && (int) $cm->course !== $courseid)) {
            throw new \moodle_exception('invalidparameter', 'error');
        }
        return $cm;
    }

    /**
     * Protect spreadsheet imports against formula evaluation.
     *
     * @param mixed $value CSV cell.
     * @return string Safe cell.
     */
    public static function csv_cell($value): string {
        $value = (string) $value;
        return preg_match('/^[\s\x00-\x20]*[=+@-]/u', $value) ? "'" . $value : $value;
    }
}

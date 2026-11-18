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
 * Delete student scan data page for TAG Proctoring.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 Andrei Toma <https://www.tandreig.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/accessrule/tagproctoring/lib.php');

\quizaccess_tagproctoring\local\request_guard::require_post();
require_sesskey();

$cid = \quizaccess_tagproctoring\local\request_guard::post_param('cid', PARAM_INT);
$cmid = \quizaccess_tagproctoring\local\request_guard::post_param('cmid', PARAM_INT);
$qid = \quizaccess_tagproctoring\local\request_guard::post_param('qid', PARAM_INT);
$userid = \quizaccess_tagproctoring\local\request_guard::post_param('userid', PARAM_INT);

$cm = \quizaccess_tagproctoring\local\request_guard::quiz_module($qid, $cmid, $cid);
[$course, $cm] = get_course_and_cm_from_cmid($cm->id, 'quiz');
$context = context_module::instance($cm->id, MUST_EXIST);

require_login($course, true, $cm);
require_capability('quizaccess/tagproctoring:manage', $context);

$reporturl = quizaccess_tagproctoring_get_report_url($cid, $cmid, $qid);

global $DB, $PAGE, $OUTPUT;

// The read-only confirmation lives in confirm_delete_scan.php.
$scan = $DB->get_record('quizaccess_tagproctoring_scan', [
    'quizid' => $qid,
    'userid' => $userid,
]);

if ($scan) {
    $fs = get_file_storage();

    foreach (['roomscan', 'selfie', 'idphoto'] as $filearea) {
        $fs->delete_area_files(
            $context->id,
            'quizaccess_tagproctoring',
            $filearea,
            $userid
        );
    }

    $DB->delete_records('quizaccess_tagproctoring_scan', [
        'id' => $scan->id,
    ]);
}

redirect(
    $reporturl,
    get_string('deletescan_success', 'quizaccess_tagproctoring'),
    3,
    \core\output\notification::NOTIFY_SUCCESS
);

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
 * Webcam screenshot upload endpoint for TAG Proctoring.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 Andrei Toma <https://www.tandreig.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once(__DIR__ . '/lib.php');

// Prevent any PHP notices/warnings from corrupting the JSON response.
ob_start();

header('Content-Type: application/json');

try {
    require_login();
    require_sesskey();

    $cmid = required_param('cmid', PARAM_INT);
    $attemptid = required_param('attemptid', PARAM_INT);

    global $DB, $USER;

    $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
    $quizid = $cm->instance;
    $context = context_module::instance($cm->id, MUST_EXIST);
    require_capability('mod/quiz:attempt', $context);

    // Validate attempt belongs to this user.
    $attempt = $DB->get_record('quiz_attempts', [
        'id' => $attemptid, 'userid' => $USER->id, 'quiz' => $quizid, 'state' => 'inprogress',
    ], '*', MUST_EXIST);

    if (empty($_FILES['screenshot']) || !is_uploaded_file($_FILES['screenshot']['tmp_name'])) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => get_string('error_no_screenshot', 'quizaccess_tagproctoring')]);
        exit;
    }

    // The source records what produced the screenshot: a periodic 'screen'/'webcam' capture, or the
    // event that triggered it (tab switch, window blur, right click, face away/turn, ...). The report
    // keys its per-shot labels off exactly these values, so the allow-list must match the monitor
    // triggers. PARAM_ALPHA strips everything except letters — safe for use in the filename.
    $source = optional_param('source', 'webcam', PARAM_ALPHA);
    $periodicsources = ['webcam', 'screen', 'periodic'];
    $allowedsources = array_merge($periodicsources, quizaccess_tagproctoring_screenshot_events());
    if (!in_array($source, $allowedsources, true)) {
        throw new moodle_exception('invalidparameter', 'error');
    }

    // Caps per attempt keep storage predictable with hundreds of concurrent students: periodic
    // captures are limited by "Max screenshots per attempt"; event-triggered captures (at most one
    // per logged event) by "Max event logs per attempt", so a long exam with periodic shots can
    // never use up the evidence for a tab switch or a second person later on.
    if (in_array($source, $periodicsources, true)) {
        $maxshots = quizaccess_tagproctoring_attempt_cap('maxscreenshots', 20, 'maxscreenshots_per_hour', (int) $quizid);
        [$insql, $inparams] = $DB->get_in_or_equal($periodicsources, SQL_PARAMS_NAMED);
        $existingcount = $DB->count_records_select(
            'quizaccess_tagproctoring_screenshots',
            "attemptid = :attemptid AND source $insql",
            ['attemptid' => $attemptid] + $inparams
        );
    } else {
        // Admin "Max event screenshots per attempt"; 0 = same cap as the event log.
        $maxshots = quizaccess_tagproctoring_timing('max_event_screenshots')
            ?: quizaccess_tagproctoring_attempt_cap('maxevents', 500, 'maxevents_per_hour', (int) $quizid);
        $existingcount = $DB->count_records('quizaccess_tagproctoring_screenshots', ['attemptid' => $attemptid]);
    }
    if ($existingcount >= $maxshots) {
        ob_end_clean();
        echo json_encode(['status' => 'ok', 'skipped' => true]);
        exit;
    }

    // Server-side rate limit (admin "Minimum seconds between screenshots"): prevents bursts even if
    // the client throttle is bypassed (e.g. multiple tabs).
    $mingap = quizaccess_tagproctoring_screenshot_min_gap();
    // A periodic capture never blocks an event capture: for events only the previous event
    // capture counts (same rule as monitor.js).
    [$notinsql, $notinparams] = $DB->get_in_or_equal($periodicsources, SQL_PARAMS_NAMED, 'p', false);
    $lastshot = $DB->get_field_sql(
        'SELECT MAX(timecreated) FROM {quizaccess_tagproctoring_screenshots} WHERE attemptid = :attemptid'
            . (in_array($source, $periodicsources, true) ? '' : " AND source $notinsql"),
        ['attemptid' => $attemptid] + $notinparams
    );
    if ($lastshot && (time() - $lastshot) < $mingap) {
        ob_end_clean();
        echo json_encode(['status' => 'ok', 'skipped' => true, 'reason' => 'rate_limit']);
        exit;
    }

    \quizaccess_tagproctoring\local\media_validator::validate_image($_FILES['screenshot']);

    $fs = get_file_storage();
    // Append a short uniqid suffix so concurrent uploads in the same second never collide.
    $filename = $source . '_' . $attemptid . '_' . $USER->id . '_' . time() . '_' . substr(uniqid(), -8) . '.jpg';

    $fileinfo = [
        'contextid' => $context->id,
        'component' => 'quizaccess_tagproctoring',
        'filearea' => 'screenshots',
        'itemid' => $USER->id,
        'filepath' => '/',
        'filename' => $filename,
    ];

    $store = \quizaccess_tagproctoring\local\s3_store::from_config();
    $stored = false;
    if ($store) {
        $key = $store->object_key($quizid, (int) $USER->id, 'screenshots', $filename);
        $stored = $store->put_file($key, $_FILES['screenshot']['tmp_name'], 'image/jpeg');
    }
    if (!$stored) {
        $fs->create_file_from_pathname($fileinfo, $_FILES['screenshot']['tmp_name']);
    }

    $DB->insert_record('quizaccess_tagproctoring_screenshots', (object) [
        'quizid' => $quizid,
        'userid' => $USER->id,
        'attemptid' => $attemptid,
        'timecreated' => time(),
        'filename' => $filename,
        'source' => $source,
    ]);

    ob_end_clean();
    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => get_string('error_general', 'quizaccess_tagproctoring')]);
}
exit;

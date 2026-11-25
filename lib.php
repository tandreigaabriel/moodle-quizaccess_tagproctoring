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
 * Library functions for TAG Proctoring quiz access rule.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 TAG Digital Solutions Ltd <https://tandreig.com>
 * @author     Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serve proctoring files (room scan videos, screenshots).
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return void|false
 */
function quizaccess_tagproctoring_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    require_login($course, false, $cm);

    if (!has_capability('quizaccess/tagproctoring:viewreport', $context)) {
        return false;
    }

    $itemid = array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'quizaccess_tagproctoring', $filearea, $itemid, $filepath, $filename);

    if (!$file) {
        // Media kept in the object store: hand the browser a short-lived presigned URL.
        $store = \quizaccess_tagproctoring\local\s3_store::from_config();
        if ($store && in_array($filearea, \quizaccess_tagproctoring\local\s3_store::AREAS, true) && $filepath === '/') {
            redirect($store->presigned_url($store->object_key((int) $cm->instance, (int) $itemid, $filearea, $filename)));
        }
        return false;
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Build report URL.
 *
 * @param int $cid
 * @param int $cmid
 * @param int $quizid
 * @return moodle_url
 */
function quizaccess_tagproctoring_get_report_url($cid, $cmid, $quizid) {
    return new moodle_url('/mod/quiz/accessrule/tagproctoring/report.php', [
        'cid' => $cid,
        'cmid' => $cmid,
        'qid' => $quizid,
    ]);
}

/**
 * Suspicious events that take a screenshot, as configured by the admin ("Screenshot on these events").
 *
 * @return string[] Event names; all events when the setting has never been saved.
 */
function quizaccess_tagproctoring_screenshot_events(): array {
    $all = [
        'tabswitch', 'windowblur', 'contextmenu', 'copypaste', 'devtools', 'fullscreenexit',
        'screensharestop', 'multiplescreen', 'faceaway', 'faceturn', 'multiplefaces',
    ];
    $raw = get_config('quizaccess_tagproctoring', 'screenshotevents');
    if ($raw === false) {
        return $all;
    }
    return array_values(array_intersect($all, array_filter(explode(',', (string) $raw))));
}

/**
 * Defaults of the admin-adjustable timings and limits ("Timings and limits" settings).
 *
 * @return int[] name => default
 */
function quizaccess_tagproctoring_timing_defaults(): array {
    return [
        'face_check_interval' => 3,
        'face_away_seconds' => 9,
        'face_turn_seconds' => 9,
        'multiplefaces_seconds' => 6,
        'face_alert_cooldown' => 30,
        'devtools_check_interval' => 10,
        'multiplescreen_wait' => 300,
        'event_dedupe_seconds' => 5,
        'screenshot_width' => 960,
        'webcam_width' => 480,
        'screenshot_quality' => 55,
        'max_event_screenshots' => 0,
        'maxevents_per_hour' => 200,
        'maxscreenshots_per_hour' => 120,
    ];
}

/**
 * Whole hours of a quiz's time limit (at least 1), or 0 when the quiz has no time limit.
 *
 * @param int $quizid Quiz id.
 * @return int Hours.
 */
function quizaccess_tagproctoring_quiz_hours(int $quizid): int {
    global $DB;

    $timelimit = (int) $DB->get_field('quiz', 'timelimit', ['id' => $quizid]);
    return $timelimit > 0 ? max(1, (int) ceil($timelimit / HOURSECS)) : 0;
}

/**
 * Per-attempt cap sized for the exam length: the larger of the absolute setting and
 * "per hour" x hours of the quiz time limit (quizzes without a time limit use the absolute cap).
 *
 * @param string $absolutesetting Setting name of the absolute cap (e.g. 'maxevents').
 * @param int $absolutedefault Default of the absolute cap.
 * @param string $perhoursetting Timing name of the per-hour value (e.g. 'maxevents_per_hour').
 * @param int $quizid Quiz id.
 * @return int Cap, at least 1.
 */
function quizaccess_tagproctoring_attempt_cap(
    string $absolutesetting,
    int $absolutedefault,
    string $perhoursetting,
    int $quizid
): int {
    $absolute = (int) get_config('quizaccess_tagproctoring', $absolutesetting) ?: $absolutedefault;
    $perhour = quizaccess_tagproctoring_timing($perhoursetting);
    $hours = quizaccess_tagproctoring_quiz_hours($quizid);
    return max(1, ($perhour > 0 && $hours > 0) ? max($absolute, $perhour * $hours) : $absolute);
}

/**
 * One timing/limit as configured by the admin, or its default when unset.
 *
 * @param string $name Setting name (see quizaccess_tagproctoring_timing_defaults()).
 * @return int Value, never below 0.
 */
function quizaccess_tagproctoring_timing(string $name): int {
    $defaults = quizaccess_tagproctoring_timing_defaults();
    $raw = get_config('quizaccess_tagproctoring', $name);
    return max(0, ($raw === false || $raw === '') ? (int) ($defaults[$name] ?? 0) : (int) $raw);
}

/**
 * Timings passed to the in-exam monitor (amd/src/monitor.js), keyed as the JS expects them.
 *
 * @return int[]
 */
function quizaccess_tagproctoring_monitor_timings(): array {
    return [
        'faceCheckInterval' => quizaccess_tagproctoring_timing('face_check_interval'),
        'faceAwaySeconds' => quizaccess_tagproctoring_timing('face_away_seconds'),
        'faceTurnSeconds' => quizaccess_tagproctoring_timing('face_turn_seconds'),
        'multipleFacesSeconds' => quizaccess_tagproctoring_timing('multiplefaces_seconds'),
        'faceAlertCooldown' => quizaccess_tagproctoring_timing('face_alert_cooldown'),
        'devtoolsCheckInterval' => quizaccess_tagproctoring_timing('devtools_check_interval'),
        'multipleScreenWait' => quizaccess_tagproctoring_timing('multiplescreen_wait'),
        'screenshotWidth' => quizaccess_tagproctoring_timing('screenshot_width'),
        'webcamWidth' => quizaccess_tagproctoring_timing('webcam_width'),
        'screenshotQuality' => quizaccess_tagproctoring_timing('screenshot_quality'),
    ];
}

/**
 * Minimum seconds between two screenshots of one attempt ("Minimum seconds between screenshots").
 *
 * @return int Seconds, at least 1.
 */
function quizaccess_tagproctoring_screenshot_min_gap(): int {
    $raw = get_config('quizaccess_tagproctoring', 'screenshotmingap');
    return max(1, ($raw === false || $raw === '') ? 2 : (int) $raw);
}

/**
 * Calculate risk score and level for one attempt.
 *
 * @param array    $eventcounts   Associative array of eventtype => count.
 * @param bool     $scansuspicious  Whether the room scan was flagged.
 * @param int|null $facematch     Face-match result: 1=match, 0=mismatch, -1=unknown, null=not checked.
 * @return array  ['score' => int, 'risk' => 'high'|'medium'|'low']
 */
function quizaccess_tagproctoring_calculate_attempt_risk(
    array $eventcounts,
    bool $scansuspicious,
    ?int $facematch = null
): array {
    $plg = 'quizaccess_tagproctoring';

    $highrisk = [
        'screenshare'     => (int) (get_config($plg, 'risk_score_screenshare') ?: 50),
        'screensharestop' => (int) (get_config($plg, 'risk_score_screenshare') ?: 50),
        'multiplescreen'  => (int) (get_config($plg, 'risk_score_multiplescreen') ?: 50),
        'multiplefaces'   => 40,
        'noface'          => 20,
        'faceaway'        => 20,
    ];
    $medrisk = [
        'fullscreenexit'   => (int) (get_config($plg, 'risk_score_fullscreenexit') ?: 20),
        'tabswitch'        => (int) (get_config($plg, 'risk_score_tabswitch') ?: 10),
        'windowblur'       => (int) (get_config($plg, 'risk_score_tabswitch') ?: 10),
        'visibilityhidden' => (int) (get_config($plg, 'risk_score_visibilityhidden') ?: 10),
        'copypaste'        => (int) (get_config($plg, 'risk_score_copypaste') ?: 10),
        'devtools'         => (int) (get_config($plg, 'risk_score_devtools') ?: 5),
        'faceturn'         => 10,
        'capabilitywarning' => 20,
    ];
    $scanscore       = (int) (get_config($plg, 'risk_score_suspicious_scan') ?: 30);
    $thresholdhigh   = (int) (get_config($plg, 'risk_threshold_high') ?: 50);
    $thresholdmedium = (int) (get_config($plg, 'risk_threshold_medium') ?: 20);

    $score = 0;

    foreach ($highrisk as $type => $pts) {
        if (!empty($eventcounts[$type])) {
            $score += $pts;
        }
    }

    foreach ($medrisk as $type => $pts) {
        if (!empty($eventcounts[$type])) {
            $score += min($pts * $eventcounts[$type], $pts * 3);
        }
    }

    if ($scansuspicious) {
        $score += $scanscore;
    }

    // Face-match penalty (selfie vs ID photo comparison during room scan).
    if ($facematch !== null) {
        if ($facematch === 0) {
            $score += 40; // Clear mismatch.
        } else if ($facematch === -1) {
            $score += 10; // Unknown, no face detected in selfie.
        }
    }

    // The report shows the score out of 100; keep it on that scale.
    $score = min($score, 100);

    $risk = $score >= $thresholdhigh ? 'high' : ($score >= $thresholdmedium ? 'medium' : 'low');

    return ['score' => $score, 'risk' => $risk];
}

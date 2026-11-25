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
 * ID photo upload endpoint for TAG Proctoring.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 TAG Digital Solutions Ltd <https://tandreig.com>
 * @author     Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

ob_start();
header('Content-Type: application/json');

try {
    require_login();

    $toolarge = \quizaccess_tagproctoring\local\media_validator::request_too_large();
    if ($toolarge !== '') {
        http_response_code(413);
        echo json_encode([
            'status' => 'error',
            'message' => get_string('error_upload_too_large', 'quizaccess_tagproctoring', $toolarge),
        ]);
        exit;
    }

    require_sesskey();
    $quizid = required_param('quizid', PARAM_INT);

    global $DB, $USER;

    $cm = get_coursemodule_from_instance('quiz', $quizid, 0, false, MUST_EXIST);
    $context = context_module::instance($cm->id, MUST_EXIST);
    require_capability('mod/quiz:attempt', $context);
    \quizaccess_tagproctoring\local\scan_record::require_replacement_allowed($quizid, (int) $USER->id);

    if (empty($_FILES['idphoto']) || !is_uploaded_file($_FILES['idphoto']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => get_string('error_no_idphoto', 'quizaccess_tagproctoring')]);
        exit;
    }

    \quizaccess_tagproctoring\local\media_validator::validate_image($_FILES['idphoto']);

    $fs = get_file_storage();
    $filename = 'idphoto_' . $quizid . '_' . $USER->id . '_' . time() . '.jpg';

    $fileinfo = [
        'contextid' => $context->id,
        'component' => 'quizaccess_tagproctoring',
        'filearea' => 'idphoto',
        'itemid' => $USER->id,
        'filepath' => '/',
        'filename' => $filename,
    ];

    // Delete previous ID photo for this user quiz.
    foreach ($fs->get_area_files($context->id, 'quizaccess_tagproctoring', 'idphoto', $USER->id, 'timecreated DESC', false) as $f) {
        $f->delete();
    }

    $store = \quizaccess_tagproctoring\local\s3_store::from_config();
    $stored = false;
    if ($store) {
        $store->delete_prefix($store->object_key($quizid, (int) $USER->id, 'idphoto'));
        $key = $store->object_key($quizid, (int) $USER->id, 'idphoto', $filename);
        $stored = $store->put_file($key, $_FILES['idphoto']['tmp_name'], 'image/jpeg');
    }
    if (!$stored) {
        $fs->create_file_from_pathname($fileinfo, $_FILES['idphoto']['tmp_name']);
    }

    \quizaccess_tagproctoring\local\scan_record::save_image($quizid, (int) $USER->id, 'idphoto', $filename);

    ob_end_clean();
    echo json_encode(['status' => 'ok']);
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => get_string('error_general', 'quizaccess_tagproctoring')]);
}
exit;

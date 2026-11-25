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
 * Validate media before replacing stored evidence.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 TAG Digital Solutions Ltd <https://tandreig.com>
 * @author     Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring\local;

/**
 * Limits and content validation for uploaded evidence.
 */
class media_validator {
    /** @var int Maximum compressed image size (10 MiB). */
    public const IMAGE_BYTES = 10485760;
    /** @var int Maximum room recording size (100 MiB). */
    public const VIDEO_BYTES = 104857600;
    /** @var int Bound decoded image memory (16 megapixels). */
    public const IMAGE_PIXELS = 16000000;

    /**
     * Detect a request that PHP already truncated because of its upload limits.
     *
     * When the body exceeds post_max_size PHP discards $_POST and $_FILES, so the
     * sesskey check fails and the student only sees a generic error. Call this
     * after require_login() and before require_sesskey().
     *
     * @param array|null $files Upload descriptors (defaults to $_FILES).
     * @param int|null $contentlength Request body size (defaults to CONTENT_LENGTH).
     * @return string Human-readable server limit when too large, empty string otherwise.
     */
    public static function request_too_large(?array $files = null, ?int $contentlength = null): string {
        $files = $files ?? $_FILES;
        $contentlength = $contentlength ?? (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $limit = (int) get_max_upload_file_size();
        $toolarge = $limit > 0 && $contentlength > $limit;
        foreach ($files as $file) {
            if (in_array($file['error'] ?? UPLOAD_ERR_OK, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $toolarge = true;
            }
        }
        return $toolarge ? display_size($limit) : '';
    }

    /**
     * Validate actual bytes on disk, ignoring client MIME and size claims.
     *
     * @param array $file PHP upload descriptor.
     * @param int $limit Plugin size limit.
     * @return string Temporary path.
     */
    private static function validate_size(array $file, int $limit): string {
        global $CFG;
        $path = $file['tmp_name'] ?? '';
        $sitecap = (int) ($CFG->maxbytes ?? 0);
        if ($sitecap > 0) {
            $limit = min($limit, $sitecap);
        }
        $size = is_file($path) ? filesize($path) : false;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !$size || $size > $limit) {
            throw new \moodle_exception('error_invalid_media', 'quizaccess_tagproctoring');
        }
        return $path;
    }

    /**
     * Decode an allowed image, bounding allocation before decoding.
     *
     * @param array $file PHP upload descriptor.
     */
    public static function validate_image(array $file): void {
        $path = self::validate_size($file, self::IMAGE_BYTES);
        $info = @getimagesize($path);
        if (
            !$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)
                || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::IMAGE_PIXELS
        ) {
            throw new \moodle_exception('error_invalid_media', 'quizaccess_tagproctoring');
        }
        $image = @imagecreatefromstring(file_get_contents($path));
        if (!$image) {
            throw new \moodle_exception('error_invalid_media', 'quizaccess_tagproctoring');
        }
        imagedestroy($image);
    }

    /**
     * Allow only recognised WebM or MP4 containers, never client MIME alone.
     *
     * @param array $file PHP upload descriptor.
     * @return string Canonical extension.
     */
    public static function validate_video(array $file): string {
        $path = self::validate_size($file, self::VIDEO_BYTES);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $head = file_get_contents($path, false, null, 0, 4096);
        // Older libmagic reports WebM as Matroska; the EBML header and DocType remain mandatory.
        if (
            in_array($mime, ['video/webm', 'video/x-matroska'], true) && substr($head, 0, 4) === "\x1a\x45\xdf\xa3"
                && strpos($head, 'webm') !== false
        ) {
            return 'webm';
        }
        // Safari MediaRecorder output may be reported as M4V or QuickTime; the ftyp box remains mandatory.
        if (in_array($mime, ['video/mp4', 'video/x-m4v', 'video/quicktime'], true) && substr($head, 4, 4) === 'ftyp') {
            return 'mp4';
        }
        throw new \moodle_exception('error_invalid_media', 'quizaccess_tagproctoring');
    }
}

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
 * Content and upload size regressions.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 TAG Digital Solutions Ltd <https://tandreig.com>
 * @author     Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring;

use quizaccess_tagproctoring\local\media_validator;

/**
 * Validate real decoded content and bytes, independently of client metadata.
 *
 * @covers \quizaccess_tagproctoring\local\media_validator
 */
final class media_validator_test extends \advanced_testcase {
    /**
     * Genuine JPEG and PNG images decode successfully.
     */
    public function test_valid_images(): void {
        $this->resetAfterTest();
        $image = imagecreatetruecolor(2, 2);
        foreach (['imagejpeg', 'imagepng'] as $encoder) {
            $path = tempnam(make_request_directory(), 'media');
            $encoder($image, $path);
            media_validator::validate_image(['tmp_name' => $path, 'error' => UPLOAD_ERR_OK]);
            $this->assertGreaterThan(0, filesize($path));
        }
        imagedestroy($image);
    }

    /**
     * Requests truncated by the PHP upload limits are reported with the server limit.
     */
    public function test_request_too_large(): void {
        $limit = (int) get_max_upload_file_size();
        $this->assertGreaterThan(0, $limit);
        $this->assertSame('', media_validator::request_too_large([], 10));
        $this->assertSame('', media_validator::request_too_large([['error' => UPLOAD_ERR_OK]], $limit));
        $this->assertSame(display_size($limit), media_validator::request_too_large([], $limit + 1));
        $this->assertSame(display_size($limit), media_validator::request_too_large([['error' => UPLOAD_ERR_INI_SIZE]], 10));
        $this->assertSame(display_size($limit), media_validator::request_too_large([['error' => UPLOAD_ERR_FORM_SIZE]], 10));
    }

    /**
     * Invalid bytes cannot masquerade as an image or a video.
     */
    public function test_invalid_media(): void {
        $this->resetAfterTest();
        $path = tempnam(make_request_directory(), 'media');
        file_put_contents($path, 'Synthetic invalid media');
        foreach (['validate_image', 'validate_video'] as $method) {
            try {
                media_validator::$method([
                    'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'type' => 'image/jpeg', 'size' => 1,
                ]);
                $this->fail('Invalid content was accepted.');
            } catch (\moodle_exception $e) {
                $this->assertSame('error_invalid_media', $e->errorcode);
            }
        }
    }

    /**
     * Actual file bytes and upload error take precedence over declared size.
     */
    public function test_size_and_partial_upload(): void {
        $this->resetAfterTest();
        $path = tempnam(make_request_directory(), 'media');
        $handle = fopen($path, 'wb');
        ftruncate($handle, media_validator::VIDEO_BYTES + 1);
        fclose($handle);
        foreach (['validate_image', 'validate_video'] as $method) {
            try {
                media_validator::$method(['tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 1]);
                $this->fail('Oversized media was accepted.');
            } catch (\moodle_exception $e) {
                $this->assertSame('error_invalid_media', $e->errorcode);
            }
        }
        $this->expectException(\moodle_exception::class);
        media_validator::validate_image(['tmp_name' => $path, 'error' => UPLOAD_ERR_PARTIAL]);
    }
}

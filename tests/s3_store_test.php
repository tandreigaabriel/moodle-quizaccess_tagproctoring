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
 * Object store regressions.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring;

use quizaccess_tagproctoring\local\s3_store;

/**
 * Tests for the S3 store.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 Andrei Toma
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \quizaccess_tagproctoring\local\s3_store
 */
final class s3_store_test extends \advanced_testcase {
    /**
     * Presigned URL matches the worked example from the AWS Signature Version 4 documentation
     * (examplebucket/test.txt, 24 May 2013, 86400 s).
     */
    public function test_presigned_url_matches_aws_example(): void {
        $store = new s3_store(
            'https://s3.amazonaws.com',
            'us-east-1',
            'examplebucket',
            'AKIAIOSFODNN7EXAMPLE',
            'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            false,
            ''
        );
        $store->set_time_for_tests(1369353600); // 20130524T000000Z.
        $url = $store->presigned_url('test.txt', 86400);
        $this->assertStringStartsWith('https://examplebucket.s3.amazonaws.com/test.txt?', $url);
        $this->assertStringContainsString('X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request', $url);
        $this->assertStringEndsWith(
            'X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
            $url
        );
    }

    /**
     * Uploads go to <endpoint>/<bucket>/<prefix>/<quiz>/<user>/<area>/<file> with a signed
     * Authorization header and the real payload hash; deletes use the same key.
     */
    public function test_put_and_delete_use_path_style_keys_and_signed_headers(): void {
        $calls = [];
        $transport = function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
            $calls[] = [$method, $url, $headers, $body];
            return [200, ''];
        };
        $store = new s3_store(
            'https://ref.storage.example.com/storage/v1/s3',
            'eu-central-1',
            'moodlescans',
            'key',
            'secret',
            true,
            'proctoring',
            $transport
        );
        $tmp = make_request_directory() . '/selfie.jpg';
        file_put_contents($tmp, 'jpegdata');

        $this->assertSame('proctoring/42/7/', $store->object_key(42, 7, ''));
        $key = $store->object_key(42, 7, 'selfie', 'selfie_42_7.jpg');
        $this->assertSame('proctoring/42/7/selfie/selfie_42_7.jpg', $key);
        $this->assertTrue($store->put_file($key, $tmp, 'image/jpeg'));
        $this->assertTrue($store->delete($key));

        [$method, $url, $headers, $body] = $calls[0];
        $this->assertSame('PUT', $method);
        $this->assertSame('https://ref.storage.example.com/storage/v1/s3/moodlescans/proctoring/42/7/selfie/selfie_42_7.jpg', $url);
        $this->assertSame($tmp, $body);
        $joined = implode("\n", $headers);
        $this->assertStringContainsString('x-amz-content-sha256: ' . hash('sha256', 'jpegdata'), $joined);
        $this->assertStringContainsString('content-type: image/jpeg', $joined);
        $this->assertMatchesRegularExpression(
            '/Authorization: AWS4-HMAC-SHA256 Credential=key\/\d{8}\/eu-central-1\/s3\/aws4_request, '
            . 'SignedHeaders=content-length;content-type;host;x-amz-content-sha256;x-amz-date, Signature=[0-9a-f]{64}/',
            $joined
        );
        $this->assertSame('DELETE', $calls[1][0]);
        $this->assertSame($url, $calls[1][1]);
    }

    /**
     * delete_prefix lists the folder (ListObjectsV2) and deletes each key.
     */
    public function test_delete_prefix_lists_then_deletes(): void {
        $calls = [];
        $transport = function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
            $calls[] = [$method, $url];
            if ($method === 'GET') {
                return [200, '<ListBucketResult><IsTruncated>false</IsTruncated>'
                    . '<Contents><Key>proctoring/42/7/roomscan/a.webm</Key></Contents>'
                    . '<Contents><Key>proctoring/42/7/roomscan/b.webm</Key></Contents></ListBucketResult>'];
            }
            return [204, ''];
        };
        $store = new s3_store('https://s3.example.com', 'eu-west-2', 'b', 'k', 's', true, 'proctoring', $transport);
        $this->assertSame(2, $store->delete_prefix($store->object_key(42, 7, 'roomscan')));
        $this->assertSame('GET', $calls[0][0]);
        $this->assertStringContainsString('/b?list-type=2&max-keys=1000&prefix=proctoring%2F42%2F7%2Froomscan%2F', $calls[0][1]);
        $this->assertSame(['DELETE', 'https://s3.example.com/b/proctoring/42/7/roomscan/a.webm'], $calls[1]);
        $this->assertSame(['DELETE', 'https://s3.example.com/b/proctoring/42/7/roomscan/b.webm'], $calls[2]);
    }

    /**
     * The store is off unless enabled and fully configured.
     */
    public function test_from_config_requires_complete_settings(): void {
        $this->resetAfterTest();
        $this->assertNull(s3_store::from_config());
        set_config('s3_enabled', 1, 'quizaccess_tagproctoring');
        set_config('s3_endpoint', 'https://s3.example.com', 'quizaccess_tagproctoring');
        set_config('s3_region', 'eu-west-2', 'quizaccess_tagproctoring');
        set_config('s3_bucket', 'b', 'quizaccess_tagproctoring');
        set_config('s3_key', 'k', 'quizaccess_tagproctoring');
        $this->assertNull(s3_store::from_config(), 'No secret yet.');
        set_config('s3_secret', 's', 'quizaccess_tagproctoring');
        $this->assertInstanceOf(s3_store::class, s3_store::from_config());
    }
}

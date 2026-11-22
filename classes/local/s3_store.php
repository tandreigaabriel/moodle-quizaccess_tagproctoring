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
 * Optional S3-compatible object store for proctoring media.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 Andrei Toma <https://www.tandreig.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace quizaccess_tagproctoring\local;

/**
 * Minimal S3 client (Signature Version 4) for room-scan videos, selfies, ID photos and screenshots.
 *
 * Works with AWS S3 and S3-compatible services (Supabase Storage, MinIO, Cloudflare R2, ...).
 * It deliberately does not use the AWS SDK bundled with Moodle: that SDK duplicates the path of
 * endpoints that contain one (for example .../storage/v1/s3), which breaks Supabase.
 *
 * Objects are stored under <prefix>/<quizid>/<userid>/<area>/<filename> and served to
 * invigilators through short-lived presigned URLs.
 *
 * @package    quizaccess_tagproctoring
 * @copyright  2026 Andrei Toma <https://www.tandreig.com>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class s3_store {
    /** @var string[] File areas that can live in the object store. */
    public const AREAS = ['roomscan', 'selfie', 'idphoto', 'screenshots'];

    /** @var string SHA-256 of an empty payload. */
    public const EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** @var string Endpoint without trailing slash, e.g. https://s3.eu-central-1.amazonaws.com */
    private string $endpoint;

    /** @var string Region used in the credential scope. */
    private string $region;

    /** @var string Bucket name. */
    private string $bucket;

    /** @var string Access key id. */
    private string $key;

    /** @var string Secret access key. */
    private string $secret;

    /** @var bool Path-style addressing (endpoint/bucket/key) instead of bucket.host. */
    private bool $pathstyle;

    /** @var string Key prefix inside the bucket. */
    private string $prefix;

    /** @var callable|null Test transport: fn(string $method, string $url, string[] $headers, ?string $bodypath): array{int, string} */
    private $transport;

    /** @var int|null Fixed timestamp for tests. */
    private ?int $fixedtime = null;

    /**
     * Constructor.
     *
     * @param string $endpoint Endpoint URL (may contain a path).
     * @param string $region Region for the credential scope.
     * @param string $bucket Bucket name.
     * @param string $key Access key id.
     * @param string $secret Secret access key.
     * @param bool $pathstyle Path-style addressing.
     * @param string $prefix Key prefix.
     * @param callable|null $transport Replacement HTTP transport (tests).
     */
    public function __construct(
        string $endpoint,
        string $region,
        string $bucket,
        string $key,
        string $secret,
        bool $pathstyle = true,
        string $prefix = 'proctoring',
        ?callable $transport = null
    ) {
        $this->endpoint = rtrim(trim($endpoint), '/');
        $this->region = trim($region);
        $this->bucket = trim($bucket);
        $this->key = trim($key);
        $this->secret = trim($secret);
        $this->pathstyle = $pathstyle;
        $this->prefix = trim($prefix, '/');
        $this->transport = $transport;
    }

    /**
     * Build the store from the plugin settings, or null when the object store is off or incomplete.
     *
     * @return self|null
     */
    public static function from_config(): ?self {
        $plg = 'quizaccess_tagproctoring';
        if (!get_config($plg, 's3_enabled')) {
            return null;
        }
        $endpoint = (string) get_config($plg, 's3_endpoint');
        $region = (string) get_config($plg, 's3_region');
        $bucket = (string) get_config($plg, 's3_bucket');
        $key = (string) get_config($plg, 's3_key');
        $secret = (string) get_config($plg, 's3_secret');
        if ($endpoint === '' || $region === '' || $bucket === '' || $key === '' || $secret === '') {
            return null;
        }
        $prefix = (string) get_config($plg, 's3_prefix');
        return new self(
            $endpoint,
            $region,
            $bucket,
            $key,
            $secret,
            (bool) get_config($plg, 's3_pathstyle'),
            $prefix !== '' ? $prefix : 'proctoring'
        );
    }

    /**
     * Object key for a media file; an empty filename gives the folder prefix (trailing slash).
     *
     * @param int $quizid Quiz instance id.
     * @param int $userid Student id.
     * @param string $area One of self::AREAS.
     * @param string $filename Stored filename, or '' for the folder.
     * @return string
     */
    public function object_key(int $quizid, int $userid, string $area, string $filename = ''): string {
        $parts = array_filter([$this->prefix, (string) $quizid, (string) $userid, $area], 'strlen');
        return implode('/', $parts) . '/' . $filename;
    }

    /**
     * Upload a local file.
     *
     * @param string $key Object key.
     * @param string $pathname Local file path.
     * @param string $mimetype Content type.
     * @return bool True on HTTP 2xx.
     */
    public function put_file(string $key, string $pathname, string $mimetype): bool {
        if (!is_readable($pathname)) {
            return false;
        }
        $url = $this->object_url($key);
        $headers = $this->signed_headers(
            'PUT',
            $url,
            hash_file('sha256', $pathname),
            ['content-type' => $mimetype, 'content-length' => (string) filesize($pathname)]
        );
        [$status] = $this->request('PUT', $url, $headers, $pathname);
        return $status >= 200 && $status < 300;
    }

    /**
     * Delete one object.
     *
     * @param string $key Object key.
     * @return bool True on HTTP 2xx or 404.
     */
    public function delete(string $key): bool {
        $url = $this->object_url($key);
        [$status] = $this->request('DELETE', $url, $this->signed_headers('DELETE', $url, self::EMPTY_HASH), null);
        return ($status >= 200 && $status < 300) || $status === 404;
    }

    /**
     * List object keys under a prefix (ListObjectsV2, follows continuation tokens).
     *
     * @param string $prefix Key prefix.
     * @return string[]
     */
    public function list_keys(string $prefix): array {
        $keys = [];
        $token = '';
        do {
            $query = ['list-type' => '2', 'prefix' => $prefix, 'max-keys' => '1000'];
            if ($token !== '') {
                $query['continuation-token'] = $token;
            }
            $url = $this->bucket_url() . '?' . self::canonical_query($query);
            [$status, $body] = $this->request('GET', $url, $this->signed_headers('GET', $url, self::EMPTY_HASH), null);
            if ($status < 200 || $status >= 300 || $body === '') {
                break;
            }
            $token = '';
            if (preg_match_all('/<Key>([^<]*)<\/Key>/', $body, $m)) {
                foreach ($m[1] as $k) {
                    $keys[] = html_entity_decode($k, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                }
            }
            if (
                preg_match('/<IsTruncated>true<\/IsTruncated>/', $body)
                    && preg_match('/<NextContinuationToken>([^<]*)<\/NextContinuationToken>/', $body, $t)
            ) {
                $token = html_entity_decode($t[1], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            }
        } while ($token !== '');
        return $keys;
    }

    /**
     * Delete every object under a prefix.
     *
     * @param string $prefix Key prefix (normally from object_key() with an empty filename).
     * @return int Number of objects deleted.
     */
    public function delete_prefix(string $prefix): int {
        $count = 0;
        foreach ($this->list_keys($prefix) as $key) {
            if ($this->delete($key)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Presigned GET URL (query-string authentication).
     *
     * @param string $key Object key.
     * @param int $expires Lifetime in seconds (default 10 minutes).
     * @return string
     */
    public function presigned_url(string $key, int $expires = 600): string {
        $url = $this->object_url($key);
        $parts = parse_url($url);
        $amzdate = gmdate('Ymd\THis\Z', $this->now());
        $datestamp = substr($amzdate, 0, 8);
        $scope = $datestamp . '/' . $this->region . '/s3/aws4_request';
        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->key . '/' . $scope,
            'X-Amz-Date' => $amzdate,
            'X-Amz-Expires' => (string) $expires,
            'X-Amz-SignedHeaders' => 'host',
        ];
        $canonicalquery = self::canonical_query($query);
        $canonical = "GET\n" . self::canonical_uri($parts['path'] ?? '/') . "\n" . $canonicalquery . "\n"
            . 'host:' . self::host_header($parts) . "\n\nhost\nUNSIGNED-PAYLOAD";
        $signature = $this->signature($amzdate, $scope, $canonical);
        return $url . '?' . $canonicalquery . '&X-Amz-Signature=' . $signature;
    }

    /**
     * Fix the clock (tests only).
     *
     * @param int $timestamp Unix timestamp.
     * @return void
     */
    public function set_time_for_tests(int $timestamp): void {
        $this->fixedtime = $timestamp;
    }

    /**
     * Current time.
     *
     * @return int
     */
    private function now(): int {
        return $this->fixedtime ?? time();
    }

    /**
     * URL of the bucket root (no trailing slash in path-style; the host root otherwise).
     *
     * @return string
     */
    private function bucket_url(): string {
        if ($this->pathstyle) {
            return $this->endpoint . '/' . rawurlencode($this->bucket);
        }
        $parts = parse_url($this->endpoint);
        $host = $this->bucket . '.' . ($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return ($parts['scheme'] ?? 'https') . '://' . $host . $port . ($parts['path'] ?? '');
    }

    /**
     * URL of one object.
     *
     * @param string $key Object key.
     * @return string
     */
    private function object_url(string $key): string {
        return $this->bucket_url() . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    /**
     * Signed request headers (header authentication).
     *
     * @param string $method HTTP method.
     * @param string $url Full URL.
     * @param string $payloadhash Hex SHA-256 of the body.
     * @param string[] $extra Extra headers to send and sign (lowercase names).
     * @return string[] Header lines.
     */
    private function signed_headers(string $method, string $url, string $payloadhash, array $extra = []): array {
        $parts = parse_url($url);
        $amzdate = gmdate('Ymd\THis\Z', $this->now());
        $datestamp = substr($amzdate, 0, 8);
        $scope = $datestamp . '/' . $this->region . '/s3/aws4_request';

        $headers = $extra + [
            'host' => self::host_header($parts),
            'x-amz-content-sha256' => $payloadhash,
            'x-amz-date' => $amzdate,
        ];
        ksort($headers);
        $canonicalheaders = '';
        foreach ($headers as $name => $value) {
            $canonicalheaders .= $name . ':' . trim($value) . "\n";
        }
        $signed = implode(';', array_keys($headers));
        parse_str($parts['query'] ?? '', $queryparams);
        $canonical = $method . "\n" . self::canonical_uri($parts['path'] ?? '/') . "\n"
            . self::canonical_query($queryparams) . "\n" . $canonicalheaders . "\n" . $signed . "\n" . $payloadhash;
        $signature = $this->signature($amzdate, $scope, $canonical);

        $lines = [];
        foreach ($headers as $name => $value) {
            if ($name !== 'host') {
                $lines[] = $name . ': ' . $value;
            }
        }
        $lines[] = 'Authorization: AWS4-HMAC-SHA256 Credential=' . $this->key . '/' . $scope
            . ', SignedHeaders=' . $signed . ', Signature=' . $signature;
        return $lines;
    }

    /**
     * SigV4 signature for a canonical request.
     *
     * @param string $amzdate ISO8601 basic timestamp.
     * @param string $scope Credential scope.
     * @param string $canonical Canonical request.
     * @return string Hex signature.
     */
    private function signature(string $amzdate, string $scope, string $canonical): string {
        $tosign = "AWS4-HMAC-SHA256\n" . $amzdate . "\n" . $scope . "\n" . hash('sha256', $canonical);
        $kdate = hash_hmac('sha256', substr($amzdate, 0, 8), 'AWS4' . $this->secret, true);
        $kregion = hash_hmac('sha256', $this->region, $kdate, true);
        $kservice = hash_hmac('sha256', 's3', $kregion, true);
        $ksigning = hash_hmac('sha256', 'aws4_request', $kservice, true);
        return hash_hmac('sha256', $tosign, $ksigning);
    }

    /**
     * Host header value (host plus non-default port).
     *
     * @param array $parts parse_url() result.
     * @return string
     */
    private static function host_header(array $parts): string {
        $host = $parts['host'] ?? '';
        if (isset($parts['port'])) {
            $default = (($parts['scheme'] ?? 'https') === 'https') ? 443 : 80;
            if ((int) $parts['port'] !== $default) {
                $host .= ':' . $parts['port'];
            }
        }
        return $host;
    }

    /**
     * Canonical URI: each path segment URI-encoded once (S3 does not double-encode).
     *
     * @param string $path Request path.
     * @return string
     */
    private static function canonical_uri(string $path): string {
        if ($path === '') {
            return '/';
        }
        $segments = explode('/', $path);
        foreach ($segments as $i => $segment) {
            $segments[$i] = rawurlencode(rawurldecode($segment));
        }
        return implode('/', $segments);
    }

    /**
     * Canonical query string: keys sorted, RFC 3986 encoding.
     *
     * @param array $params name => value.
     * @return string
     */
    private static function canonical_query(array $params): string {
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
        }
        return implode('&', $pairs);
    }

    /**
     * Send a request; returns [status, body].
     *
     * @param string $method HTTP method.
     * @param string $url Full URL.
     * @param string[] $headers Header lines.
     * @param string|null $bodypath File to send as the body (PUT) or null.
     * @return array{int, string}
     */
    private function request(string $method, string $url, array $headers, ?string $bodypath): array {
        if ($this->transport !== null) {
            return call_user_func($this->transport, $method, $url, $headers, $bodypath);
        }
        // The endpoint is configured by the site administrator, so Moodle's cURL blocked-hosts
        // list does not apply to it (the same as the AWS SDK used by other storage plugins).
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader($headers);
        $options = ['CURLOPT_CONNECTTIMEOUT' => 20, 'CURLOPT_TIMEOUT' => 600, 'CURLOPT_RETURNTRANSFER' => true];
        if ($method === 'PUT') {
            $body = (string) $curl->put($url, ['file' => $bodypath], $options);
        } else if ($method === 'DELETE') {
            $body = (string) $curl->delete($url, [], $options);
        } else {
            $body = (string) $curl->get($url, [], $options);
        }
        $info = $curl->get_info();
        $status = (int) ($info['http_code'] ?? 0);
        if ($curl->get_errno()) {
            debugging('Object store: ' . $method . ' ' . $url . ' failed: ' . $curl->error, DEBUG_DEVELOPER);
            return [0, ''];
        }
        return [$status, $body];
    }
}

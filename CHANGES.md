# Changes

## Unreleased

- Fixed: event-triggered screenshots (`tabswitch`, `windowblur`, `contextmenu`, `faceaway`, `faceturn`) were rejected by `screenshot_upload.php` with HTTP 500 because its `source` allow-list only accepted `webcam`/`screen`; violation screenshots were never stored.
- Fixed: uploads larger than the server's PHP limits (`post_max_size` / `upload_max_filesize`) showed the generic "An error occurred. Please try again." The room-scan, selfie and ID endpoints now return HTTP 413 with the actual server limit (`error_upload_too_large`), and `upload.php` logs the exception to `moodledata/quizaccess_tagproctoring/logs/upload.log`.
- Fixed: the "Multiple Monitors Detected" overlay now closes by itself when the second display is disconnected (`screen` `change` event + 2 s poll) and returns if a display is attached later; no page reload needed.
- Changed: room-scan recording capped at 1 Mbps (`videoBitsPerSecond`) — a 45 s scan is ~5–6 MB instead of 12–15 MB. Recommended PHP `upload_max_filesize` / `post_max_size` ≥ 32M.
- Tests: `media_validator_test::test_request_too_large` (20 tests).

## 1.0.2 (2026-11-18)

- Listed as a commercial plugin: the README states the licence terms, the 60-day trial, what is sent to the licence service and where the AI model files are downloaded from.
- Repository renamed to `moodle-quizaccess_tagproctoring`.

## 1.0.1 (2026-11-12)

- Plugins Directory review (MMR-59): GPL licence file, all user-facing text in language strings, HTML in JavaScript built with Mustache templates, upgrade savepoints in order, missing language strings added.
- Security fixes from the pre-release QA; scan deletion asks for confirmation and accepts POST only.

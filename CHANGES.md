# Changes

## Unreleased

### Fixed
- Wizard: the captured selfie / ID photo now replaces the live camera box instead of appearing under it (no empty black box, no extra scrolling).
- Wizard, ID step: auto-capture no longer fires on a face alone — something with printed edges must appear inside the green box compared with the empty box remembered at the start of the step; otherwise the student sees "No ID visible in the green box" and can still use the camera button.
- styles.css: the `@media (max-height: 820px)` block was not closed, which pulled the whole theme CSS into it (pages unstyled on viewports taller than 820 px).

- Fixed: event-triggered screenshots (`tabswitch`, `windowblur`, `contextmenu`, `faceaway`, `faceturn`) were rejected by `screenshot_upload.php` with HTTP 500 because its `source` allow-list only accepted `webcam`/`screen`; violation screenshots were never stored.
- Fixed: uploads larger than the server's PHP limits (`post_max_size` / `upload_max_filesize`) showed the generic "An error occurred. Please try again." The room-scan, selfie and ID endpoints now return HTTP 413 with the actual server limit (`error_upload_too_large`), and `upload.php` logs the exception to `moodledata/quizaccess_tagproctoring/logs/upload.log`.
- Fixed: the "Multiple Monitors Detected" overlay now closes by itself when the second display is disconnected (`screen` `change` event + 2 s poll) and returns if a display is attached later; no page reload needed.
- Changed: room-scan recording capped at 1 Mbps (`videoBitsPerSecond`) — a 45 s scan is ~5–6 MB instead of 12–15 MB. Recommended PHP `upload_max_filesize` / `post_max_size` ≥ 32M.
- Fixed: a second person in front of the camera was never reported (the monitor returned early for 2+ faces although the server scored `multiplefaces`); now logged after ~6 s with a webcam screenshot, max once per 30 s.
- Fixed: `faceaway` did not count towards the risk score and took no screenshot; now 20 points + webcam screenshot. Face-event screenshots always use the webcam.
- Fixed: screenshot rows/files and events of quiz attempts deleted in Moodle were never cleaned up; the cleanup task now removes them on every run (`tests/cleanup_files_test.php`).
- Changed: Firefox/Safari (no `screen.isExtended`) are blocked before the wizard and the attempt, with the list of supported browsers (Chromium 100+: Chrome, Edge, Brave, Opera).
- Changed: the wizard fits laptop screens without scrolling (`@media (max-height: 820px)`), verified at 1366×768, 1280×720, 1024×768.
- Tests: `media_validator_test::test_request_too_large`, `cleanup_files_test` (21 tests, 116 assertions).

## 1.0.2 (2026-11-18)

- Listed as a commercial plugin: the README states the licence terms, the 60-day trial, what is sent to the licence service and where the AI model files are downloaded from.
- Repository renamed to `moodle-quizaccess_tagproctoring`.

## 1.0.1 (2026-11-12)

- Plugins Directory review (MMR-59): GPL licence file, all user-facing text in language strings, HTML in JavaScript built with Mustache templates, upgrade savepoints in order, missing language strings added.
- Security fixes from the pre-release QA; scan deletion asks for confirmation and accepts POST only.

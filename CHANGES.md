# Changes

## Unreleased

### Added
- Object storage (S3-compatible): new admin section "Object storage (S3)" — endpoint, region, bucket, access key, secret, path-style, prefix. When enabled, room-scan videos, selfies, ID photos and screenshots are uploaded to the bucket (<prefix>/<quiz>/<user>/<area>/<file>) instead of the Moodle file store and served to invigilators through 10-minute presigned links; deleting evidence, the retention task and privacy requests remove the objects too. Own Signature V4 client (no SDK) verified against the AWS documentation example; tested live against Supabase Storage.
- Periodic webcam evidence: "Screenshot interval" (seconds, min 10) now takes a webcam capture at that interval, capped by "Max screenshots per attempt" (source: periodic). Before, the setting had no effect.
- Face turned away: "Face-turn sensitivity" now drives a real check (nose offset relative to the eye distance, 3 polls ≈ 9 s) that logs a `faceturn` event (10 risk points, capped ×3) with a webcam capture.

### Changed
- A screenshot is stored only together with its event: the frame is grabbed at the moment of the event and uploaded only when the server confirms the event was stored (not refused by the per-attempt cap or by the repeat filter). Before, a capture could be kept while its event had been dropped by "Max event logs per attempt". "Minimum seconds between screenshots" now paces the uploads (queue) instead of dropping the second picture when two events fire within the gap (right click, then copy). Developer tools: one event + picture per opening instead of an event on every check while the panel stays open (which filled the event cap in long exams). The repeat filter (same event type within a few seconds counts once) was a fixed 5 s; it is now the admin setting "Same event repeat window". README: new "Sizing the limits for your exams" section with worked examples for 125-minute and 3-hour exams.
- "Timings and limits" settings: face check interval, face away / face turned / second person seconds, face alert cooldown, DevTools check interval, second-screen capture wait, screen and webcam capture widths, JPEG quality, max event screenshots per attempt, max event logs / periodic screenshots per exam hour — nothing in the monitor is hard-coded any more, and for quizzes with a time limit the caps scale with the exam length (max(absolute, per hour x hours)). "Max event logs per attempt" default 200 → 500 (a 3-hour exam needs room; size it for your longest exam).
- New admin settings: "Screenshot on these events" (tick which events take a picture; default all) and "Minimum seconds between screenshots" (default 2; was a fixed 5 s in the browser and on the server, which dropped the picture of a copy right after a right click). A periodic capture no longer blocks an event capture taken right after it.
- Evidence is event-driven: copy/paste, developer tools (once per opening), full-screen exit and "screen sharing stopped" (webcam) now take a screenshot like tab switch / window blur / right click / face events / second screen. Periodic captures are off by default ("Screenshot interval" 0) and, when enabled, are the only captures counted against "Max screenshots per attempt"; event captures are capped by "Max event logs per attempt" (before, 20 periodic shots in a 10-minute exam used up the cap and later tab-switch evidence was silently skipped).

### Fixed
- The `attempt_started` / `attempt_deleted` observers are now internal (plain database writes). As external observers they were deferred until the surrounding transaction committed, so on PostgreSQL (where Moodle runs each PHPUnit test inside a transaction) they never ran in the tests; CI now runs the suite on PostgreSQL 14 as well as MariaDB.
- Readiness evidence can no longer be replaced at will (Marketplace review): the room scan, selfie and ID photo endpoints accept a recording only while the readiness check is required — no record yet, an incomplete record, a teacher-granted new check, or (with "new room scan for each attempt") between attempts. Once the check has been used to start an attempt it is locked (HTTP 403, "Your readiness check is already complete…"); the teacher's grant is consumed when the attempt starts (`notify_preflight_check_passed`), not when the first file is uploaded, so the whole wizard can run after a grant. The scan record now stores the attempt it was used for. PHPUnit test `test_replacement_allowed_only_while_check_required`.
- `upload.php` wrote a line to `moodledata/…/logs/upload.log` on every request before authentication (unbounded, unauthenticated file growth); the leftover debug logging is removed.
- Screen sharing was requested again on the quiz summary page (and on every quiz page): the summary page now keeps webcam/event monitoring without the full-screen + screen-share gate, and the quiz settings explain that "all questions on one page" means a single prompt (a browser screen-share stream cannot survive a page load).
- Report: clicking an event category highlights its screenshots by source as well as by time (±90 s); a second-screen capture taken once the student finally shared the screen was not shown under the event.
- A second screen (`multiplescreen`) only logged an event; it now also attaches a screenshot of the shared screen (waits up to 5 minutes for the student to start sharing; webcam only as a last resort), and the report labels `multiplefaces` / `multiplescreen` evidence instead of showing the raw source name.
- Room scan: pressing "Redo" no longer carries the objects seen in the previous take into the new one.
- Room scan: "Extra Person in Room" / "Multiple Faces Detected" were shown on the report after a single misdetected frame; the report now lists only objects seen in at least "Minimum detections before flagging" intervals, and a second person must be a separate, confident (≥ 0.5) box. Detection now runs every 1 s.
- Report: duration shows seconds under one minute ("23 s"); risk score is capped at 100; session cards fill the available width (no empty third column) with the action buttons on one row; flags panel scrolls into view and takes less empty space; missing selfie/ID thumbnails show a placeholder.
- Wizard: on laptops (≤ 820 px tall) the captured selfie/ID preview is limited so the buttons stay visible.
- Deleting a student's quiz attempt (quiz results page) now flags their room scan, selfie and ID photo as needing a new readiness check; the next attempt starts the wizard again instead of skipping it. Previous evidence is kept until the new scan replaces it (observer on mod_quiz attempt_deleted, with a PHPUnit test).
- Report: the session summary now lists "exam window focus loss" (windowblur) events; before, an attempt scored for them could still read "No violations detected".
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

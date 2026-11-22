# TAG Proctoring — Moodle Quiz Access Rule

**Component:** `quizaccess_tagproctoring`  
**Moodle:** 4.5+  
**PHP:** 8.1+  
**License:** GNU GPL v3 or later

## Overview

TAG Proctoring is a quiz access rule plugin for Moodle that enables lightweight browser-based exam proctoring. Before a student can start a quiz attempt, they are guided through a multi-step wizard that records a room scan video, captures a selfie and photo ID, and prompts for screen sharing. During the attempt, the plugin monitors for suspicious behaviour and captures periodic screenshots from both the webcam and the shared screen. Instructors review proctoring data through a dedicated report page with per-attempt risk scores, evidence carousels, and manual override controls.

## Features

### Pre-exam wizard

- Room scan video recording with AI-assisted object detection (phones, books, laptops, extra people, and configurable object classes) — detection runs every second; an object counts only when seen in at least "Minimum detections" intervals, a second person must be a separate confident detection, and "Redo" starts the count from zero
- Selfie capture with animated guide, auto-capture countdown, and blur/brightness/face validation
- Photo ID capture with alignment guide box and face detection; auto-capture fires only once an ID-like object is inside the green box (the camera button always works); the captured photo replaces the live preview
- Screen share prompt with surface-type enforcement (tab share blocked, full-screen or monitor required)
- Multi-monitor detection — blocks the exam while more than one display is active; the block lifts by itself when the extra display is unplugged and returns if one is connected during the attempt (Chromium only)
- Animated fullscreen takeover overlay with escalating warnings on repeated exits
- Mobile and unsupported browser blocking with clear messaging — Firefox and Safari are blocked before the wizard because they cannot detect a second monitor; the notice lists the supported browsers

### In-exam monitoring

- Evidence screenshots on suspicious events (tab switch, window blur, right-click, face away, face turned, second person) — from the shared screen when available, from the webcam for face events; rate-limited and capped per attempt
- Evidence is captured when something suspicious happens: tab switch, window focus loss, right click, copy/paste, developer tools, full-screen exit, screen sharing stopped (webcam), second screen, face not visible / turned, second person — capped by "Max event logs per attempt"
- Optional periodic webcam evidence every "Screenshot interval" seconds (0 = off, default; minimum 10 when on), capped by "Max screenshots per attempt"
- Event logging: tab switch, window blur, copy/paste, DevTools open, context menu, fullscreen exit, multiple screens (with a screenshot of the shared screen once sharing starts), screen-share stop
- Screen sharing is requested once per quiz page (a browser stream cannot survive a page load) and not again on the summary page; set "New page" to "Never, all questions on one page" so the student is asked only once
- Face presence via AI (BlazeFace, every "Face check interval" seconds): `faceaway` when no face is visible for "Face away after" seconds, `faceturn` when the head stays turned to the side (threshold = "Face-turn sensitivity"), `multiplefaces` when a second person is in front of the camera — all with a webcam screenshot, a risk score and a per-event cooldown
- Nothing is hard-coded: every interval, delay, size and cap is an admin setting ("Timings and limits"); caps scale with the quiz time limit ("per exam hour" values), so a 3-hour exam keeps all its evidence
- Status bar showing webcam dot, screen-share dot, and elapsed timer

### Instructor report

- Three-level drill-down: students → quiz sessions → evidence panel
- Per-attempt risk score (0–100) and risk level (High / Needs Review / Valid); duration shown in seconds under one minute
- AI summary block: plain-English event breakdown per attempt
- Flags panel with violation categories and screenshot carousel
- Room scan video playback in the report
- Selfie and ID photo display per student
- Face match verification status
- Manual override (mark attempt as Valid regardless of score)
- Mark attempt as Reviewed
- Grant rescan (allow student to re-record room scan)
- Delete screenshots, delete events, delete scan data per student; deleting a quiz attempt from Moodle's results flags the student for a new readiness check at the next attempt
- CSV export of all attempt data
- Search/filter student list

### Administration

- Risk scoring with configurable per-event weights and threshold boundaries
- Post-attempt email alerts to configured recipients when risk is Medium or High
- Grades table proctoring column showing risk badge in Moodle gradebook
- Automatic data retention cleanup via scheduled task (also removes rows left behind by deleted attempts)
- Optional S3-compatible object storage for all media (AWS S3, Supabase Storage, MinIO, Cloudflare R2): uploads go straight to the bucket, invigilators open them through 10-minute presigned links, and deletes, retention and privacy requests remove the objects too
- Full Moodle Privacy API (GDPR data export and deletion)
- Licence key management with automatic activation

## Requirements

- Moodle 4.5 or later
- PHP 8.1 or later
- HTTPS required — webcam, screen share, and MediaRecorder APIs require a secure context
- Browser: Google Chrome, Microsoft Edge, Brave or Opera — Chromium 100 or newer on Windows, macOS or Linux (the Window Management API is needed for multi-monitor detection). Firefox, Safari, phones and tablets are blocked with a notice.
- PHP `upload_max_filesize` and `post_max_size` of at least 32M (a 45-second room scan is about 5–6 MB; the student sees the server limit if an upload is rejected)
- Storage: by default all media goes through the Moodle File API (so `tool_objectfs` can offload it); alternatively enable the plugin's own "Object storage (S3)" section to keep videos and photos in an S3-compatible bucket. The retention task deletes expired and orphaned files wherever they are stored
- A TAG Proctoring licence key for the site, obtained through Moodle Marketplace (see *Commercial licence*)

## Commercial licence

TAG Proctoring is a **commercial plugin sold through Moodle Marketplace**. The code is GPL v3 or later;
using it on a site requires a licence key for that site.

- **Purchase:** one licence per Moodle site (domain), billed yearly, bought on the plugin's Moodle
  Marketplace listing. The licence key is issued after the purchase and is bound to the site's domain.
- **Trial:** a 60-day trial key can be requested from the Marketplace listing before purchasing.
- **Without a valid key** proctoring cannot be enabled on quizzes. Quizzes themselves keep working,
  without proctoring. After a licence expires there is a seven-day grace period.
- **What is sent to the licence service:** the site domain, the licence key and the Moodle version, to
  `https://www.tandreig.com/api/license/activate`, when the key is activated and once a week by a
  scheduled task. No student data, recordings or quiz data are sent to it; all proctoring data stays
  on your Moodle site.
- **AI models:** the TensorFlow.js libraries are bundled with the plugin, but the student's browser
  downloads the COCO-SSD and BlazeFace model files from Google's TensorFlow model hosting when the
  pre-exam check starts. Webcam images are analysed inside the browser and are never sent there.
- Terms: [tandreig.com/terms-and-conditions](https://tandreig.com/terms-and-conditions) ·
  Privacy: [tandreig.com/privacy-policy](https://tandreig.com/privacy-policy)

## Source and issue tracker

[Source repository](https://github.com/tandreigaabriel/moodle-quizaccess_tagproctoring) ·
[Issues](https://github.com/tandreigaabriel/moodle-quizaccess_tagproctoring/issues)

## Installation

1. Copy the `tagproctoring` folder into `mod/quiz/accessrule/` inside your Moodle installation so the path is:
   ```
   mod/quiz/accessrule/tagproctoring/
   ```
2. Log in as a Moodle administrator and go to **Site administration → Notifications** to run the database upgrade. This creates the required tables.
3. Enter the licence key received with your Moodle Marketplace purchase (or the trial key requested from the listing — see *Commercial licence*): go to **Site administration → Plugins → Quiz access rules → TAG Proctoring** and paste it. The plugin contacts the activation service and stores the licence status.
4. Enable the access rule on a quiz via **Quiz settings → Extra restrictions on attempts → Enable TAG Proctoring**.

## Configuration

### Global settings — Site administration → Plugins → Quiz access rules → TAG Proctoring

| Setting                     | Description                                                             |
| --------------------------- | ----------------------------------------------------------------------- |
| Licence key                 | External activation key — contact the provider for terms                          |
| Enable room scan            | Master switch for the room scan wizard step                             |
| Enable screenshots          | Master switch for in-exam screenshot capture                            |
| Screenshot on these events  | Which suspicious events take a screenshot (default: all)                |
| Minimum seconds between screenshots | Spacing between event captures, browser + server (default: 2): the frame is grabbed at the moment of the event and queued, so the gap paces uploads instead of dropping evidence; periodic captures never block an event capture |
| Enable event logging        | Master switch for in-exam event tracking                                |
| Data retention period       | Days before scan videos and screenshots are purged (default: 30)        |
| Screenshot interval         | Seconds between periodic webcam captures; 0 = off (default), min 10    |
| Max screenshots per attempt | Cap for periodic captures per attempt (default: 20); event captures are capped by Max event logs |
| AI confidence threshold     | Minimum confidence for object detection (default: 0.35)                 |
| Flagged object types        | One COCO-SSD class per line that triggers a suspicious flag             |
| Minimum detections          | Intervals (~1 s) an object must be seen in before it counts (default: 3)|
| Face-turn sensitivity       | Nose offset / eye distance above which the head counts as turned (0.30)|
| Timings and limits          | Face check interval (3 s), face away after (9 s), face turned after (9 s), second person after (6 s), face alert cooldown (30 s), DevTools check interval (10 s), second-screen capture wait (300 s), same event repeat window (5 s), screen capture width (960 px), webcam capture width (480 px), JPEG quality (55), max event screenshots per attempt (0 = Max event logs), max event logs per exam hour (200), max periodic screenshots per exam hour (120) — for quizzes with a time limit the caps become max(absolute, per hour x hours) |
| High risk threshold         | Risk score at or above which an attempt is marked High (default: 60)    |
| Medium risk threshold       | Score at or above which an attempt is marked Needs Review (default: 20) |
| Email alerts                | Enable post-attempt alert emails                                        |
| Alert recipients            | Comma-separated email addresses; defaults to quiz teachers when empty   |
| Object storage (S3)         | Enable, endpoint URL, region, bucket, access key, secret, path-style, key prefix — see below |

### Sizing the limits for your exams

Nothing is hard-coded: the monitor stops **storing** evidence for an attempt once a cap is reached (the exam itself continues), so size the caps from your longest exam, not from the default. A screenshot is only stored together with its event: if the event cap is reached, or the same event repeats inside the "Same event repeat window", no picture is taken either.

**How the caps work**

- Quiz **with a time limit**: cap = the larger of the absolute value and `per exam hour x hours` (hours rounded up). Defaults: events 500 absolute / 200 per hour; event screenshots = the event cap; periodic screenshots 20 absolute / 120 per hour (one every 30 s).
- Quiz **without a time limit**: only the absolute values apply - set them for the longest session you expect (rule of thumb: 200 events per hour).

| Exam length (time limit) | Hours used | Event cap (500 / 200 per h) | Periodic cap, interval 30 s (20 / 120 per h) |
| ------------------------ | ---------- | --------------------------- | -------------------------------------------- |
| 45 min                   | 1          | 500                         | 120                                          |
| 125 min                  | 3          | 600                         | 360                                          |
| 3 h                      | 3          | 600                         | 360                                          |
| 4 h                      | 4          | 800                         | 480                                          |

**Recommended set-up for 2-3 hour exams**

1. Quiz settings: give the quiz a time limit (the caps scale from it) and set "Layout → New page: Never, all questions on one page" so screen sharing is requested once.
2. "Screenshot on these events": tick everything you want evidence for (default: all); "Minimum seconds between screenshots": 2.
3. "Max event logs per attempt" 500 and "per exam hour" 200 cover a 3-hour exam (600). Raise the per-hour value if your students generate more alerts (e.g. many face-away alerts when they read printed material - or raise "Face away after" to 15-20 s instead).
4. Periodic screenshots: leave "Screenshot interval" at 0 unless you need a timeline; 60 s with 60 per hour is a good compromise for long exams.
5. "Data retention period": at least the length of your appeal window (default 30 days); evidence is deleted from Moodle **and** from the S3 bucket after that.
6. Storage (measured on real hardware): a screen capture (960 px, quality 55) is roughly 25-100 KB depending on what is on the screen, a webcam capture (480 px) 10-30 KB, a 45-second room scan 3-6 MB. Example: 300 students x 3 h with ~20 events each ~ 300 x 20 x 50 KB ~ 0.3 GB per exam plus ~1.5 GB of room scans; with periodic captures every 30 s add 360 x 15 KB ~ 5 MB per student. Use the S3 option for anything beyond a few hundred students.

### Object storage (S3-compatible)

1. Create a private bucket and an access key with read/write/delete on it.
2. Fill in the "Object storage (S3)" settings: endpoint (AWS: `https://s3.<region>.amazonaws.com`; Supabase: `https://<project>.storage.supabase.co/storage/v1/s3`; MinIO: your server URL), region, bucket, key, secret. Keep "Path-style addressing" on for Supabase/MinIO/R2; turn it off only for AWS virtual-hosted URLs.
3. Tick "Store media in an S3-compatible bucket". From now on new room-scan videos, selfies, ID photos and screenshots are uploaded to `<prefix>/<quiz id>/<user id>/<area>/<file>`; files already in Moodle keep being served from Moodle. If an upload to the bucket fails, the file is kept in Moodle instead.
4. The plugin signs requests itself (AWS Signature V4) and does not need the AWS SDK or `tool_objectfs`. Invigilators receive 10-minute presigned links, so the bucket can stay private.

### Per-quiz settings — Quiz settings → Extra restrictions on attempts

| Setting                                | Description                                                                |
| -------------------------------------- | -------------------------------------------------------------------------- |
| Enable TAG Proctoring                  | Activates proctoring for this quiz                                         |
| Require room scan                      | Forces the room scan step in the wizard                                    |
| Room scan duration                     | Maximum recording time in seconds for this quiz (overrides global, min 30) |
| Require new room scan for each attempt | Student must re-scan before each attempt, not just the first               |

## Author & Support

**Author:** Andrei Toma — [tandreig.com](https://www.tandreig.com)  
**Support:** [tandreig.com/plugins/support](https://tandreig.com/plugins/support)  
**Licence key:** issued through Moodle Marketplace (purchase or trial request on the listing)

### v1.0.0 — 2026-07-12

- Initial stable release
- Fixed: PHPCS — 0 errors, 0 warnings across all plugin files
- Fixed: Mustache CI — example context added to report.mustache, HTML validation errors resolved
- Fixed: PSR12 — blank line after class opening brace in send_attempt_alert.php
- Fixed: Missing lang string `wizard_id_checklist_title`
- Fixed: Lang file rewritten with all 260+ strings in strict lexicographic order, section comments removed

## Maintenance update 1.0.1

This update requires the standard Moodle plugin upgrade before the new code is used.
It adds a small per-attempt record for one-time preflight timer compensation.
The record is independent of the instructor's editable event list and is included in
Privacy API export and erasure. Retention removes it after the completed attempt expires.

Student uploads never provide the instructor's identity-verification verdict. Replacing
a selfie or ID photo clears the previous verdict so an instructor can review the new evidence.
Images must decode as JPEG or PNG, have at most 16 million pixels, and be at most 10 MiB.
Recordings must be recognised WebM or MP4 containers of at most 100 MiB. Site upload limits also apply.
The video check validates the container type; it does not transcode or decode every video frame.

Regression tests in `tests/` require Moodle's separate PHPUnit database and dataroot.
Never initialise or run that suite against a restored site containing user data.

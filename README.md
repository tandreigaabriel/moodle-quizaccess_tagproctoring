# TAG Proctoring — Moodle Quiz Access Rule

**Component:** `quizaccess_tagproctoring`  
**Moodle:** 4.5+  
**PHP:** 8.1+  
**License:** GNU GPL v3 or later

## Overview

TAG Proctoring is a quiz access rule plugin for Moodle that enables lightweight browser-based exam proctoring. Before a student can start a quiz attempt, they are guided through a multi-step wizard that records a room scan video, captures a selfie and photo ID, and prompts for screen sharing. During the attempt, the plugin monitors for suspicious behaviour and captures periodic screenshots from both the webcam and the shared screen. Instructors review proctoring data through a dedicated report page with per-attempt risk scores, evidence carousels, and manual override controls.

## Features

### Pre-exam wizard

- Room scan video recording with AI-assisted object detection (phones, books, laptops, extra people, and configurable object classes)
- Selfie capture with animated guide, auto-capture countdown, and blur/brightness/face validation
- Photo ID capture with alignment guide box, face detection, and auto-capture
- Screen share prompt with surface-type enforcement (tab share blocked, full-screen or monitor required)
- Multi-monitor detection — blocks the exam while more than one display is active; the block lifts by itself when the extra display is unplugged and returns if one is connected during the attempt (Chromium only)
- Animated fullscreen takeover overlay with escalating warnings on repeated exits
- Mobile and unsupported browser blocking with clear messaging — Firefox and Safari are blocked before the wizard because they cannot detect a second monitor; the notice lists the supported browsers

### In-exam monitoring

- Evidence screenshots on suspicious events (tab switch, window blur, right-click, face away, second person) — from the shared screen when available, from the webcam for face events; rate-limited and capped per attempt
- Event logging: tab switch, window blur, copy/paste, DevTools open, context menu, fullscreen exit, multiple screens, screen-share stop
- Face presence via AI (BlazeFace, every 3 s): `faceaway` when no face is visible for ~9 s, `multiplefaces` when a second person is in front of the camera — both with a webcam screenshot and a risk score
- Status bar showing webcam dot, screen-share dot, and elapsed timer

### Instructor report

- Three-level drill-down: students → quiz sessions → evidence panel
- Per-attempt risk score and risk level (High / Needs Review / Valid)
- AI summary block: plain-English event breakdown per attempt
- Flags panel with violation categories and screenshot carousel
- Room scan video playback in the report
- Selfie and ID photo display per student
- Face match verification status
- Manual override (mark attempt as Valid regardless of score)
- Mark attempt as Reviewed
- Grant rescan (allow student to re-record room scan)
- Delete screenshots, delete events, delete scan data per student
- CSV export of all attempt data
- Search/filter student list

### Administration

- Risk scoring with configurable per-event weights and threshold boundaries
- Post-attempt email alerts to configured recipients when risk is Medium or High
- Grades table proctoring column showing risk badge in Moodle gradebook
- Automatic data retention cleanup via scheduled task
- Full Moodle Privacy API (GDPR data export and deletion)
- Licence key management with automatic activation

## Requirements

- Moodle 4.5 or later
- PHP 8.1 or later
- HTTPS required — webcam, screen share, and MediaRecorder APIs require a secure context
- Browser: Google Chrome, Microsoft Edge, Brave or Opera — Chromium 100 or newer on Windows, macOS or Linux (the Window Management API is needed for multi-monitor detection). Firefox, Safari, phones and tablets are blocked with a notice.
- PHP `upload_max_filesize` and `post_max_size` of at least 32M (a 45-second room scan is about 5–6 MB; the student sees the server limit if an upload is rejected)
- Storage: all media goes through the Moodle File API, so `tool_objectfs` can offload it transparently to S3, Azure or GCS; the retention task deletes expired and orphaned files wherever they are stored
- A TAG Proctoring licence key for the site (60-day trial key or paid licence, see *Commercial licence*)

## Commercial licence

TAG Proctoring is a **commercial plugin**. The code is GPL v3 or later; using it on a site requires a
licence key for that site.

- **Free trial:** request a trial key at
  [tandreig.com/request-key](https://tandreig.com/request-key?plugin=tagproctoring). Trial keys are
  valid for 60 days.
- **Paid licence:** one licence per Moodle site (domain), billed yearly. Current price and terms:
  [tandreig.com/plugins/proctoring-system](https://tandreig.com/plugins/proctoring-system).
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
  Privacy: [tandreig.com/privacy-policy](https://tandreig.com/privacy-policy) ·
  Support: [tandreig.com/plugins/support](https://tandreig.com/plugins/support?plugin=proctoring-system)

## Source and issue tracker

[Source repository](https://github.com/tandreigaabriel/moodle-quizaccess_tagproctoring) ·
[Issues](https://github.com/tandreigaabriel/moodle-quizaccess_tagproctoring/issues)

## Installation

1. Copy the `tagproctoring` folder into `mod/quiz/accessrule/` inside your Moodle installation so the path is:
   ```
   mod/quiz/accessrule/tagproctoring/
   ```
2. Log in as a Moodle administrator and go to **Site administration → Notifications** to run the database upgrade. This creates the required tables.
3. Request a trial key at **[tandreig.com/request-key](https://tandreig.com/request-key?plugin=tagproctoring)** or buy a licence (see *Commercial licence*). Then go to **Site administration → Plugins → Quiz access rules → TAG Proctoring** and paste it. The plugin contacts the activation service and stores the licence status.
4. Enable the access rule on a quiz via **Quiz settings → Extra restrictions on attempts → Enable TAG Proctoring**.

## Configuration

### Global settings — Site administration → Plugins → Quiz access rules → TAG Proctoring

| Setting                     | Description                                                             |
| --------------------------- | ----------------------------------------------------------------------- |
| Licence key                 | External activation key — contact the provider for terms                          |
| Enable room scan            | Master switch for the room scan wizard step                             |
| Enable screenshots          | Master switch for in-exam screenshot capture                            |
| Enable event logging        | Master switch for in-exam event tracking                                |
| Data retention period       | Days before scan videos and screenshots are purged (default: 30)        |
| Screenshot interval         | Seconds between automatic screenshots (default: 30)                     |
| Max screenshots per attempt | Storage cap per attempt (default: 20)                                   |
| AI confidence threshold     | Minimum confidence for object detection (default: 0.35)                 |
| Flagged object types        | Comma-separated list of COCO-SSD classes that trigger a suspicious flag |
| High risk threshold         | Risk score at or above which an attempt is marked High (default: 60)    |
| Medium risk threshold       | Score at or above which an attempt is marked Needs Review (default: 20) |
| Email alerts                | Enable post-attempt alert emails                                        |
| Alert recipients            | Comma-separated email addresses; defaults to quiz teachers when empty   |

### Per-quiz settings — Quiz settings → Extra restrictions on attempts

| Setting                                | Description                                                                |
| -------------------------------------- | -------------------------------------------------------------------------- |
| Enable TAG Proctoring                  | Activates proctoring for this quiz                                         |
| Require room scan                      | Forces the room scan step in the wizard                                    |
| Room scan duration                     | Maximum recording time in seconds for this quiz (overrides global, min 30) |
| Require new room scan for each attempt | Student must re-scan before each attempt, not just the first               |

## Author & Support

**Author:** Andrei Toma — [tandreig.com](https://www.tandreig.com)  
**Plugin page:** [tandreig.com/plugins/proctoring-system](https://tandreig.com/plugins/proctoring-system)  
**Support:** [tandreig.com/plugins/support](https://tandreig.com/plugins/support)  
**Licence key:** [tandreig.com/request-key](https://tandreig.com/request-key?plugin=tagproctoring) — availability and terms are set by the provider

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

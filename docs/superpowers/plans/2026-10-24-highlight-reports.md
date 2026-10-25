# Highlight Reports (Flipbook) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The office or an assigned coordinator uploads a finished highlight-report PDF for one activity, and anyone, signed in or not, can open its cover on a public Highlights page and turn its pages as a page-curl flipbook.

**Architecture:** One new table (`highlight_reports`, one row per activity) and one domain file (`includes/highlights.php`) that owns the visibility rule, the size limits, the Range parser and the store/replace/delete logic. Four pages under `public/highlights/`: a public cover grid, a public viewer, a public PDF route that answers HTTP Range requests, and a signed-in upload page. PDF.js and StPageFlip are self-hosted under `public/assets/vendor/`; one script, `public/assets/js/highlights.js`, draws the covers and runs the flipbook in the reader's browser.

**Tech Stack:** Native PHP 8.1+ (8.2.12 here), MariaDB 10.4, no framework, no build step. PDF.js `pdfjs-dist` legacy build (Apache-2.0), StPageFlip `page-flip` 2.0.7 (MIT).

**Spec:** `C:\xampp\htdocs\BukSU_CASDEVU\docs\superpowers\specs\2026-10-24-highlight-reports-design.md` (not committed; read it by that path). Also read `AGENTS.md` in the worktree before any task.

**Worktree:** `C:\xampp\htdocs\BukSU_CASDEVU-highlights`, branch `feature/highlight-reports`. Served with `C:\xampp\php\php.exe -S 127.0.0.1:8083 -t public` from the worktree (already running during the build).

## Global Constraints

- Follow `AGENTS.md` security rules 1–10 and frontend rules 1–10 in full; they are not repeated per task.
- The `casms` database is shared with other builds running in parallel. **Never** load `database/schema.sql` or `database/demo-data.sql`. Database changes go in `database/update-highlight-reports.sql` (additive only: `CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`) and are mirrored in `schema.sql`. Apply with `C:\xampp\mysql\bin\mysql.exe -u root casms < database\update-highlight-reports.sql`.
- "The office" means `is_office_staff()` (staff and admin); item 7 (removing the staff role) is undecided. Never hard-code `'admin'` alone for highlight permissions.
- A report is visible, and may be uploaded, only while its activity's status is `upcoming`, `ongoing`, `completed` or `closed`. One rule, `HIGHLIGHT_STATUSES`, used by the upload check, the public list, the viewer and the PDF route.
- Upload: PDF only, checked by extension **and** MIME (`application/pdf` from `finfo`). Default limit **40 MB**, setting `highlight_max_upload_mb`, never above the server's `min(upload_max_filesize, post_max_size)` (both 40M here). Separate from the 5 MB requirement limit.
- Posted at once, no approval step, no notifications or announcements.
- One report per activity; uploading again replaces the file. Replace/delete: anyone passing `require_activity_access()`.
- PDF.js and StPageFlip self-hosted under `public/assets/vendor/`, licence files shipped beside them, linked with `asset()`. No CDN, no build step, no npm at runtime.
- No image tools on the server: covers and pages are drawn in the browser. Nothing converted server-side.
- Copy: plain, official voice, as the existing pages. Status in words via `status_badge()`.
- Run after each task: `C:\xampp\php\php.exe -l <each touched .php>` and `C:\xampp\php\php.exe bin\smoke.php --base=http://127.0.0.1:8083`.
- Before adding any PHP function, grep its name across `includes/` and `public/` (AGENTS "duplicate function names").
- Commit after each task; messages in the repo's style (imperative sentence), ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Decisions this plan makes (left to the builder by the spec)

- **Upload form** lives at `public/highlights/manage.php`. Without `activity_id` it lists the activities the person may manage, with each one's report state ("choose the activity"); with `activity_id` it shows the title + PDF form, the current report, and Delete. The activity page's Manage card links to it.
- **Covers** are rendered in the browser on the grid (page 1 via PDF.js, lazily as each card scrolls into view), with the report title on a navy card as the fallback. No second stored file, so a replaced PDF can never show a stale cover.
- **File clean-up:** deleting an activity deletes its report file before the cascade (same place requirement files are removed).
- **Viewer accessibility:** arrow keys turn pages; Prev/Next are real buttons; a polite live region announces "Pages 2–3 of 12"; the Download PDF link sits outside the stage and is the accessible way to read the report; `prefers-reduced-motion` turns pages without the curl animation.

## Review Focus

1. **Upload larger than `post_max_size`.** PHP empties `$_POST` and `$_FILES`; the handler must show "This file is larger than 40 MB." instead of the expired-form page. (Task 3, Step 5 test.)
2. **A file that is not really a PDF** (a PNG renamed `.pdf`, or a real PDF named `.txt`). Refused with a plain message, and nothing left in `storage/uploads/highlights/`. (Task 1 self-check + Task 3 Step 5.)
3. **Activity moved to `draft` or `cancelled` after posting.** Report disappears from the grid, the viewer and the PDF route answer 404, the file stays on disk, and moving back to `upcoming` shows it again. (Task 2 Step 4.)
4. **Range edge cases**: `bytes=0-99`, suffix `bytes=-500`, open `bytes=500-`, start past the end (416), several ranges or garbage (whole file, 200). (Task 1 self-check + Task 2 smoke check.)
5. **A coordinator posting to an activity they are not assigned to** with a crafted `activity_id`, or a title containing markup. 403, and titles always escaped. (Task 3 Step 5.)

---

### Task 1: Table, domain functions, storage

**Files:**
- Create: `database/update-highlight-reports.sql`
- Modify: `database/schema.sql` (new table after `announcements`-related tables, before `settings`; new settings row in the `INSERT INTO settings` list)
- Modify: `docs/02-database-design.md` (one short section for the table, matching the file's existing style)
- Create: `includes/highlights.php`
- Modify: `includes/bootstrap.php` (require `highlights.php` after `uploads.php`)
- Modify: `includes/uploads.php` (`store_upload()` gains an optional size ceiling)
- Create: `storage/uploads/highlights/.gitkeep`; Modify: `.gitignore` (add `!storage/uploads/highlights/` beside the requirements/inventory lines)
- Create: `bin/test-highlights.php` (assert-based self-check, CLI only, same header style as other `bin/` scripts)

**Interfaces:**
- Produces (all in `includes/highlights.php`):
  - `const HIGHLIGHT_STATUSES = ['upcoming', 'ongoing', 'completed', 'closed'];`
  - `highlight_visible_status(string $status): bool`
  - `highlight_status_sql(string $column = 'a.status'): string` — returns e.g. `a.status IN ('upcoming','ongoing','completed','closed')`, built from the constant only.
  - `php_ini_bytes(string $value): int` — `'40M'` → 41943040, `'512K'`, `'1G'`, plain bytes; `'0'`, `''` or `'-1'` → `PHP_INT_MAX` (no limit).
  - `highlight_server_limit_bytes(): int` — `min(php_ini_bytes(ini_get('upload_max_filesize')), php_ini_bytes(ini_get('post_max_size')))`.
  - `highlight_max_bytes(): int` — `min(office_setting('highlight_max_upload_mb', '40') MB, server limit)`, at least 1 MB.
  - `published_highlights(): array` — every visible report, newest activity first (`a.start_at DESC`), each row: `activity_id, title, file_size, updated_at, activity_title, start_at, end_at, category`.
  - `published_highlight(int $activityId): ?array` — one row as above plus `file_path, original_name`, or null when missing or hidden.
  - `highlight_for_activity(int $activityId): ?array` — the row whatever the activity's status (for the upload page).
  - `save_highlight(int $activityId, string $title, array $file, int $userId): array{ok: bool, error?: string, replaced?: bool}`
  - `remove_highlight(int $activityId): bool` — deletes row and file; false when there was none.
  - `parse_byte_range(?string $header, int $size): array|false|null` — `[start, end]` (inclusive) for one satisfiable range; `false` for unsatisfiable (→ 416); `null` for absent, malformed or multi-range (→ serve the whole file, 200).
- `store_upload(array $file, string $subdirectory, ?int $maxBytes = null): array` — `null` keeps today's `max_upload_bytes()`; every existing caller unchanged.

- [ ] **Step 1: Write the self-check `bin/test-highlights.php`**

CLI only (404 when not CLI, like `bin/smoke.php`), requires `includes/bootstrap.php`, uses a tiny `check(bool $ok, string $label)` that prints `[ OK ]`/`[FAIL]` and exits 1 on any failure. Assertions, exactly:

```php
check(parse_byte_range(null, 1000) === null,              'no header serves whole file');
check(parse_byte_range('bytes=0-99', 1000) === [0, 99],   'first 100 bytes');
check(parse_byte_range('bytes=500-', 1000) === [500, 999],'open-ended range');
check(parse_byte_range('bytes=-500', 1000) === [500, 999],'suffix range');
check(parse_byte_range('bytes=-5000', 1000) === [0, 999], 'suffix longer than file');
check(parse_byte_range('bytes=900-5000', 1000) === [900, 999], 'end clamped to size');
check(parse_byte_range('bytes=1000-', 1000) === false,    'start past end is 416');
check(parse_byte_range('bytes=50-10', 1000) === null,     'reversed range ignored');
check(parse_byte_range('bytes=0-1,5-9', 1000) === null,   'multi-range serves whole file');
check(parse_byte_range('items=0-9', 1000) === null,       'other unit ignored');
check(php_ini_bytes('40M') === 41943040,                  '40M');
check(php_ini_bytes('512K') === 524288,                   '512K');
check(php_ini_bytes('1G') === 1073741824,                 '1G');
check(php_ini_bytes('0') === PHP_INT_MAX,                 '0 means no limit');
check(highlight_visible_status('completed') && !highlight_visible_status('draft') && !highlight_visible_status('cancelled'), 'status rule');
check(highlight_status_sql() === "a.status IN ('upcoming','ongoing','completed','closed')", 'status SQL');
check(highlight_max_bytes() <= highlight_server_limit_bytes(), 'limit never above server');
```

- [ ] **Step 2: Run it and see it fail**

Run: `C:\xampp\php\php.exe bin\test-highlights.php` — Expected: fatal "Call to undefined function parse_byte_range()".

- [ ] **Step 3: Write the SQL and apply it**

`highlight_reports`: `report_id` INT UNSIGNED AI PK; `activity_id` INT UNSIGNED NOT NULL **UNIQUE**, FK → `activities` ON DELETE CASCADE; `title` VARCHAR(200) NOT NULL; `file_path` VARCHAR(255) NOT NULL (relative to `UPLOAD_PATH`); `original_name` VARCHAR(255) NOT NULL; `file_size` INT UNSIGNED NOT NULL; `uploaded_by` INT UNSIGNED NULL, FK → `users` ON DELETE SET NULL; `created_at`, `updated_at` as other tables. Settings row: `('highlight_max_upload_mb', '40', 'Maximum highlight report PDF size')` via `INSERT IGNORE` in the update script. The update script's header comment follows `database/update-user-preferences.sql`. Mirror both in `schema.sql`. Apply the update script to `casms` (never the schema). Check: `mysql -u root casms -e "DESCRIBE highlight_reports"`.

- [ ] **Step 4: Implement `includes/highlights.php` and the `store_upload()` parameter**

`save_highlight()` order matters (spec anti-goal "a failed or partial upload leaves a broken report posted"):
1. the activity exists and `highlight_visible_status()`; title 1–200 chars after trim; original name ends in `.pdf` (case-insensitive) — else return an error before touching disk;
2. `store_upload($file, 'highlights', highlight_max_bytes())`; then if `mime !== 'application/pdf'`, `delete_upload()` the new file and return "Only PDF files are accepted.";
3. `INSERT … ON DUPLICATE KEY UPDATE` the row inside `try`; on failure `delete_upload()` the new file, log `[CASMS]`, return a generic error;
4. only after the row points at the new file, `delete_upload()` the old path.
Size message reuses `store_upload()`'s "That file is larger than N MB." `remove_highlight()` deletes the row first, then the file. Audit logging stays in the page (Task 3), as elsewhere.

- [ ] **Step 5: Run the self-check and the gate**

Run: `C:\xampp\php\php.exe bin\test-highlights.php` — Expected: every line `[ OK ]`, exit 0. Lint the touched files; run smoke — Expected: all green (no page uses the new code yet).

- [ ] **Step 6: Commit** — `Add the highlight report table and its storage rules` (include this plan file in the commit).

---

### Task 2: Public PDF route with Range support, and a signed-out smoke pass

**Files:**
- Create: `public/highlights/file.php`
- Modify: `bin/smoke.php`

**Interfaces:**
- Consumes: `published_highlight()`, `parse_byte_range()`, `upload_absolute_path()`, `sanitize_filename()`.
- Produces: URL `highlights/file.php?activity_id=N` (inline PDF) and `&download=1` (attachment). Used by Tasks 3–5.
- Smoke page keys: `'guest' => 'allow'` (signed-out request must be 200, or 404 when `optional`) and `'guest' => 'login'` (signed-out request must be a 302 to `login.php`). `$ids['highlight']` = activity id of one visible report, or 0.

- [ ] **Step 1: Extend `bin/smoke.php` first**

- `http_request()` gains `array $headers = []` (passed to `CURLOPT_HTTPHEADER`) and returns the raw response headers as `'headers'`.
- `$ids['highlight']` from `SELECT h.activity_id FROM highlight_reports h JOIN activities a ON a.activity_id = h.activity_id WHERE a.status IN ('upcoming','ongoing','completed','closed') ORDER BY h.activity_id LIMIT 1`, inside `try`: a missing table is a `fail()` telling the reader to run `database/update-highlight-reports.sql`.
- After the signed-in loop, a section **Signed out**: one fresh, empty cookie jar; for every page with a `guest` key, request it and apply the expectation above, plus the same 5xx / error-log / body-marker checks.
- A section **Range requests**, only when `$ids['highlight'] > 0`, signed out: `Range: bytes=0-99` on `highlights/file.php?activity_id=…` must answer **206** with `Content-Range: bytes 0-99/<size>` and a 100-byte body; `Range: bytes=999999999-` must answer **416**; no Range header must answer 200 with `Accept-Ranges: bytes`.
- Page entries: `['path' => 'highlights/file.php', 'allow' => [all four], 'params' => ['activity_id' => 'highlight'], 'optional' => true, 'binary' => true, 'guest' => 'allow']`. Update the header comment's list of what smoke covers.

- [ ] **Step 2: Run smoke and see the new checks fail**

Create the temporary row from Step 4 first (so `$ids['highlight']` is set), then run smoke with `--only=highlights` — Expected: FAIL, the Range section gets 404 because `highlights/file.php` does not exist yet.

- [ ] **Step 3: Implement `public/highlights/file.php`**

No `require_login()` — public by design; say so in the header comment, citing the spec. `get_id('activity_id')`; `published_highlight()`; missing/hidden → `http_response_code(404); exit;` (binary route, like `inventory/photo.php`). Confirm the file exists via `upload_absolute_path()` and is `application/pdf` by `finfo`, else 404. Call `session_write_close()` before streaming so a 40 MB read does not hold the session lock. Headers: `Content-Type: application/pdf`, `Accept-Ranges: bytes`, `Content-Length`, `Content-Disposition: inline|attachment; filename="<sanitize_filename(title)>.pdf"`, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'; object-src 'none'`, `Cache-Control: public, max-age=300` (short, so a hidden report stops being served soon), `Last-Modified` from `updated_at`. 206 with `Content-Range: bytes S-E/SIZE` for a range; 416 with `Content-Range: bytes */SIZE` for `false`. Stream with `fopen`/`fseek`/`fread` in 64 KB chunks, stopping on `connection_aborted()`. No audit row per read (public reads are not audited).

- [ ] **Step 4: Verify by hand with a temporary row**

Copy any small multi-page PDF into `storage/uploads/highlights/`, insert a row for activity 19 (`completed`) with `mysql`, then: `curl -s -D - -o NUL -H "Range: bytes=0-99" "http://127.0.0.1:8083/highlights/file.php?activity_id=19"` → `206`, `Content-Range: bytes 0-99/<size>`. Set activity 19 to `draft` → 404; back to `completed` → 200 again; file still on disk throughout. Restore activity 19's original status (`completed`). Delete the temporary row and file afterwards (Task 3 posts the real one through the form).

- [ ] **Step 5: Run the gate** — lint, smoke green (Range section runs while the temporary row exists).

- [ ] **Step 6: Commit** — `Serve highlight report PDFs publicly with Range support`

---

### Task 3: Upload page, settings limit, activity links, role matrix

**Files:**
- Create: `public/highlights/manage.php`
- Modify: `public/settings.php` (fourth office field)
- Modify: `public/activities/manage.php` (delete handler removes the report file)
- Modify: `public/activities/view.php` (Manage card link; "Read the highlights" link)
- Modify: `bin/smoke.php` (page entries)
- Modify: `docs/03-role-matrix.md`, `AGENTS.md` (rule 7 list of serving scripts gains `highlights/file.php`), `README.md` (known limits: PHP `upload_max_filesize`/`post_max_size` cap the highlight limit and are a hosting requirement)

**Interfaces:**
- Consumes: everything from Task 1; `require_activity_access()`, `can_manage_activity()`, `is_office_staff()`, `audit_log()`.
- Produces: `highlights/manage.php` and `highlights/manage.php?activity_id=N`; `highlights/view.php?activity_id=N` is linked here and built in Task 5 (until then the link 404s; smoke does not request it yet).

- [ ] **Step 1: Add the smoke entries**

`highlights/manage.php` allow admin, staff, coordinator, `'guest' => 'login'`; `highlights/manage.php` with `'params' => ['activity_id' => 'assigned']`, `'optional' => true`, same allow. Run smoke `--only=highlights` — Expected: FAIL, 404 for the missing page.

- [ ] **Step 2: Implement `public/highlights/manage.php`**

Gate: `require_role(['staff', 'admin', 'coordinator'])`; with an `activity_id`, also `require_activity_access($activityId)` and 404 for a missing activity. Keep `activity_id` in the form's `action` URL so it survives an oversized POST.

POST order: (a) **oversized** — `is_post() && $_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > highlight_server_limit_bytes()` → error "This file is larger than N MB." (N = `highlight_max_bytes()` in MB), no `csrf_verify()`, nothing saved; (b) `csrf_verify()`; (c) `action=upload` → `save_highlight()`; on ok `audit_log($replaced ? 'update' : 'create', 'highlight_report', $activityId, …)`, flash "Posted. Anyone can now read it on the Highlights page." (or "Replaced. …"), redirect to `highlights/view.php?activity_id=N`; (d) `action=delete` → `remove_highlight()`, audit `delete`, flash, redirect back to the form.

List view (no id): office sees every activity, a coordinator only assigned ones (join `activity_coordinators`), newest first; each row: activity title (link to `activities/view.php`), date, `status_badge($status, 'activity')`, report title or "No report", and an Upload/Replace `btn-outline btn-sm` link. A row whose status hides reports says so in words ("Hidden while draft"). Empty state for "nothing to manage yet". Use the existing `item`/list or `table.data` pattern that pages like `activities/coordinators.php` use; phone width must not scroll sideways.

Form view: page head with the activity name; if the status hides reports, an `alert` explaining when it can be posted (the four statuses) and no upload form; else the form — Title (prefilled with the current title, or "<activity title> Highlights"), PDF file (`accept="application/pdf,.pdf"`), hint "PDF only, up to N MB. The first page becomes the cover.", submit `btn-primary` with `data-loading="Uploading…"`. Current report card: title, `format_filesize()`, last updated, "Open" link to the viewer, Delete as a POST form (`btn-outline`). Page's one gold button: none needed (form submit is primary).

- [ ] **Step 3: Settings field**

Add `'highlight_max_upload_mb' => ['Largest highlight report', <current MB from highlight_max_bytes()>]` to `$officeFields`; validate 1…`floor(highlight_server_limit_bytes() / 1048576)`; message "Highlight report limit must be a whole number of megabytes between 1 and N, this server's limit."; `max` attribute on the input is the same N; hint "Up to N MB on this server. A higher limit needs upload_max_filesize and post_max_size raised in php.ini first."; summary row reads "N MB per highlight report PDF". Save it with the others and include it in the audit text.

- [ ] **Step 4: Activity pages and docs**

`activities/manage.php` delete handler: before the `DELETE`, `delete_upload()` the activity's report file path (cascade removes the row); count it in the flash/audit text only if present. `activities/view.php`: in the Manage card add "Highlight report" linking to `highlights/manage.php?activity_id=N`; for any signed-in viewer, when `published_highlight($activityId)` exists, a "Read the highlights" `btn-outline` link to the viewer near the page-head actions. Role matrix: new "Highlights" section with "Upload / replace / delete a highlight report: ✘ / ◐ assigned only / ✔ / ✔" and "Read highlight reports: ✔ / ✔ / ✔ / ✔", plus one line "Read highlight reports also works signed out (public page); a Guest account cannot upload."

- [ ] **Step 5: Verify the review-focus cases by hand**

Sign in through the browser or curl with a cookie jar as `bautista.coach@buksu.edu.ph` / `demo1234` and as admin:
1. Upload a real multi-page PDF for Intramurals 2026 as the coordinator → redirected to the viewer URL, row present, file in `storage/uploads/highlights/`.
2. Upload again → replaced; the old file is gone from disk, exactly one file for that activity.
3. POST a 41 MB body (`fsutil file createnew big.pdf 43000000`) → page shows "This file is larger than 40 MB.", not the expired-form page; no new file.
4. A PNG renamed `x.pdf`, and a real PDF renamed `x.txt` → refused; no new file left behind.
5. As the coordinator, POST to `highlights/manage.php?activity_id=<an activity not assigned to them>` → 403.
6. Title `<b>x</b>` → shown escaped on the list and form.
7. Settings: admin sets 41 → refused; 30 → saved, hint on the form reads 30 MB.
Leave the Intramurals 2026 report in place for later tasks and smoke.

- [ ] **Step 6: Run the gate** — lint, smoke green (now including Range and signed-out checks with a real report). Look at both manage views at 390px and desktop.

- [ ] **Step 7: Commit** — `Let the office and coordinators post an activity's highlight report`

---

### Task 4: Vendor libraries and the public cover grid

**Files:**
- Create: `public/assets/vendor/pdfjs/pdf.min.mjs`, `public/assets/vendor/pdfjs/pdf.worker.min.mjs`, `public/assets/vendor/pdfjs/LICENSE` (from `pdfjs-dist` latest, **legacy** build: `legacy/build/`), `public/assets/vendor/page-flip/page-flip.browser.js`, `public/assets/vendor/page-flip/LICENSE` (from `page-flip` 2.0.7, `dist/js/`), and a `public/assets/vendor/README.md` naming each package, version, source URL and licence.
- Create: `public/highlights/index.php`, `public/assets/js/highlights.js`
- Modify: `public/assets/css/style.css` (cover grid), `includes/icons.php` (a `highlights` icon), `includes/layout/header.php` (sidebar link for every signed-in role; `navSection`/`$sections` entry; a minimal public top bar when signed out: brand and a "Sign in" link), `public/login.php` (one plain link "Read the unit's highlight reports" under the form), `bin/smoke.php`

**Interfaces:**
- Consumes: `published_highlights()`, `highlights/file.php`.
- Produces: `highlights.js` behaviours keyed on data attributes, read by Task 5:
  - `[data-hl-cover]` on a `<canvas>` inside a cover card, with `data-pdf="<file url>"`: render page 1 when scrolled into view, then add class `is-drawn` to the card; on any failure leave the fallback.
  - `[data-hl-lib]` on `<body>` or the grid/viewer root: `data-hl-lib="<asset pdf.min.mjs>"` and `data-hl-worker="<asset pdf.worker.min.mjs>"`. The script loads PDF.js with `await import(libUrl)` and sets `GlobalWorkerOptions.workerSrc`.
  - `getDocument({ url, isEvalSupported: false, disableAutoFetch: true, disableStream: true })` — always pass `isEvalSupported: false` (PDFs are uploaded by staff but read by the public).
  - Task 5 adds `[data-flipbook]` to the same file.

- [ ] **Step 1: Fetch the libraries into a scratch directory, then copy**

Download the two tarballs from `registry.npmjs.org` with `curl` into a new empty directory outside the repo, extract with `tar`, copy only the files listed above. Do not run anything from the package. Record versions in `vendor/README.md`.

- [ ] **Step 2: Add smoke entries and see them fail**

`highlights/index.php` allow all four, `'guest' => 'allow'`. Run smoke `--only=highlights` → FAIL 404.

- [ ] **Step 3: Design pass with the impeccable skill**

Invoke the `impeccable:impeccable` skill for the cover grid, holding to `DESIGN.md` and `style.css` tokens (`--navy`, `--gold`, `--r` 14px on surfaces, no shadow at rest, Anton display / Outfit text, page head as the standard band). Covers keep a portrait aspect (A4, `aspect-ratio: 1 / 1.414`) so the grid does not jump when a cover draws; 2 columns at 390px, more from tablet up; focus ring visible on the cover link.

- [ ] **Step 4: Implement `public/highlights/index.php` and the cover part of `highlights.js`**

No `require_login()`; header comment says it is public by design. Page head "Highlights" with a one-line intro; the one `btn-gold` "Post a report" only for `is_office_staff()` or a coordinator, linking to `highlights/manage.php`. Each card: a link to `highlights/view.php?activity_id=N` holding the cover canvas, the report title, and the activity name with its date. Empty state: says no report has been posted yet and that the office or an activity's coordinator posts one after the activity (uploaders also get the link). Load `highlights.js` with `asset()` as a `defer` classic script; it dynamically imports PDF.js only when a `[data-hl-cover]` or `[data-flipbook]` exists. Covers render at the card's CSS width × `devicePixelRatio` (cap 2), through an `IntersectionObserver`.

- [ ] **Step 5: Check it** — lint, smoke green (signed-out grid 200). Open `http://127.0.0.1:8083/highlights/index.php` signed out and signed in at 390px and 1280px; the Intramurals cover draws from page 1; no sideways scroll; no console errors.

- [ ] **Step 6: Commit** — `Add the public Highlights page with self-hosted PDF.js covers`

---

### Task 5: The flipbook viewer

**Files:**
- Create: `public/highlights/view.php`
- Modify: `public/assets/js/highlights.js` (flipbook), `public/assets/css/style.css` (viewer stage and controls), `bin/smoke.php`

**Interfaces:**
- Consumes: `published_highlight()`, the loader and `[data-hl-lib]` attributes from Task 4, StPageFlip global `St.PageFlip`.
- Produces: `[data-flipbook]` element with `data-pdf="<file url>"`; children `[data-flip-prev]`, `[data-flip-next]`, `[data-flip-status]` (live region), `[data-flip-stage]`.

- [ ] **Step 1: Add the smoke entry and see it fail** — `highlights/view.php`, all four roles, `'params' => ['activity_id' => 'highlight']`, `'optional' => true`, `'guest' => 'allow'`. Run → FAIL 404.

- [ ] **Step 2: Design pass with the impeccable skill** for the viewer frame: the stage is the one place a dark field (`--navy`) sits behind the pages (spec); controls readable on it; Download PDF and "All highlights" outside the stage; title and activity name/date in the page head. At 390px a single page fills the width with controls below; on desktop two pages side by side with the spine shadow.

- [ ] **Step 3: Implement `public/highlights/view.php`**

Public. 404 via `abort_page(404, 'This highlight report is not available.')` when `published_highlight()` is null. Page head: report title, activity name and date; for a signed-in viewer a link to the activity page. Stage with Prev/Next buttons (`icon('chevron-…')` plus sr-only text "Previous page"/"Next page"), the status line, and a `<noscript>` line pointing to Download PDF. Under it: Download PDF (`highlights/file.php?activity_id=N&download=1`, with size from `format_filesize()`) and "All highlights". Load `page-flip.browser.js` (defer) before `highlights.js`.

- [ ] **Step 4: Implement the flipbook in `highlights.js`**

- Open the document (same options as covers); read page 1's viewport for the aspect ratio.
- Build one `div.flip-page` per page, each with a canvas; `new St.PageFlip(stage, { width, height, size: 'stretch', minWidth: 240, maxWidth: 1000, minHeight: 340, maxHeight: 1414, showCover: true, usePortrait: true, mobileScrollSupport: false, maxShadowOpacity: 0.5, flippingTime: reducedMotion ? 1 : 700 })`, then `loadFromHTML()`. StPageFlip switches to one page itself when the stage is narrower than two pages.
- Render lazily: pages `0…3` first, then on every `flip` event the window `current − 2 … current + 3`; never render the same page twice at the same size; cap scale at `devicePixelRatio` 2.
- Prev/Next call `flipPrev()`/`flipNext()`; `ArrowLeft`/`ArrowRight` on `document` do the same unless focus is in a form field; disable Prev on the first page and Next on the last.
- Status line: "Page 1 of 12" for a single page, "Pages 2–3 of 12" for a spread, updated on `flip` and on orientation change.
- On a load failure, replace the stage with an `alert` saying the report could not be displayed and pointing to Download PDF.

- [ ] **Step 5: Check it** — lint, smoke green. In the browser, signed out, with the real multi-page PDF from Task 3: at 1280px two pages with a curl and spine shadow, arrows/keys/drag turn pages, later pages appear as reached (network panel: several 206 responses, not one 200 for the whole file); at 390px one page, swipe turns, no sideways scroll; reduced motion turns without the curl; no console errors.

- [ ] **Step 6: Commit** — `Open highlight reports as a page-turning flipbook`

---

### Task 6: Whole-branch verification

- [ ] Lint every PHP file the branch touched (`git diff --name-only main -- '*.php'`).
- [ ] `C:\xampp\php\php.exe bin\test-highlights.php` → all OK.
- [ ] `C:\xampp\php\php.exe bin\smoke.php --base=http://127.0.0.1:8083` → 0 failed, including the Signed out and Range sections.
- [ ] Browser, signed out: grid and flipbook at 390px and desktop with the real multi-page PDF; screenshots kept out of the repo.
- [ ] Re-run Review Focus 3 (draft hides, back to published shows) against the real report.
- [ ] Do not merge into `main`; report the branch as ready.

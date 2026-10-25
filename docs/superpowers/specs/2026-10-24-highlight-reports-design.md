# Highlight reports (flipbook): design

Date: 24 October 2026
Client item: "6. Newsletter (narrative report)". The client calls it a
highlight report: the admin or a coordinator uploads it, and the system posts
it.
Status: **approved** on 24 October 2026. Not built yet; the next step is a
build plan (`superpowers:writing-plans`).

## Intent

After an activity, the unit produces a booklet or magazine-style highlight
report, for example "Intramurals 2026 Highlights". **Making the report is the
client's job, done outside the system. CASDevU only stores it and displays
it.** The people who made it upload the finished PDF, and the system posts it
as a public, page-turning flipbook.

**Model:** BukSU's own quarterly newsletter, *Sayuda*
(https://buksu.edu.ph/official-publication/). It is a grid of covers, and each
issue opens as a Heyzine flipbook embedded in the page. CASDevU builds its
own viewer instead of using Heyzine: the office uploads only a PDF, and no
outside account is needed.

Success: a coordinator uploads a PDF and, without further steps, anyone on the
internet can open a cover and turn its pages like a magazine, on a computer
or a phone.

## Decided with the client's representative

- **Upload and display only.** No editing, layout or writing inside CASDevU.
- **Who uploads:**
  - the admin (the office), for any activity;
  - a coordinator, for the activities they are assigned to.
- **One report belongs to one activity.** A report is optional; an activity
  without one shows nothing.
- **Public.** The system will be published online (see
  `docs/feature-backlog.md`, "Decisions that affect every item"), and highlight
  reports can be read without signing in, like Sayuda.
- **Viewer: page curl, like Heyzine.** The page lifts and turns over like
  paper, with a shadow at the spine. It shows two pages side by side on a
  computer and one page on a phone, turned by arrows, swipe or keyboard. Chosen
  over a simpler sliding book view from a clickable demo.

## Calls made in this document (accepted with the playback)

- **Posted at once.** The report is published as soon as it is uploaded, with
  no approval step, matching the client's words ("upload it so it's posted").
- **Upload form:** choose the activity, give a title, upload the PDF.
- **The cover is the PDF's first page.** There is no separate image upload.
- **One report per activity.** Uploading again replaces the file.
- **Who may replace or delete:** anyone who may manage the activity, using the
  existing `can_manage_activity()` / `require_activity_access()`. That is the
  office and every coordinator currently assigned to it. Who uploaded it does
  not matter, so a coordinator who is later unassigned loses access.
- **PDF only, with its own size limit,** set by the admin in Settings and
  separate from the 5 MB requirement-document limit.
  - The **default is 40 MB**, which this server's PHP already allows.
  - A higher limit needs the server's PHP limits raised first, and that
    becomes a hosting requirement when the system goes online.
  - The Settings page must never offer a value above what the server accepts.
- **When the activity's status allows a report:**
  - a report can be uploaded, and is publicly visible, only while its activity
    is `upcoming`, `ongoing`, `completed` or `closed`;
  - if the activity moves to `draft` or `cancelled`, its report disappears
    from the Highlights page and the PDF route refuses it, but the file is
    kept;
  - moving back to an allowed status shows the report again.

  The upload check, the public list and the public PDF route use one shared
  rule, so the three cannot disagree.
- **No notifications or announcements** when a report is posted. It simply
  appears on the Highlights page. The uploader can post an announcement
  separately if they want to.
- **A public "Highlights" page:**
  - a grid of covers, newest first, each with the activity's name and date;
  - opening one shows the flipbook, with a **Download PDF** link under it.
- **On the activity's own page,** a "Read the highlights" link appears for
  signed-in users once a report exists.

## Goals

- Uploading a finished PDF is the only work the uploader does.
- The flipbook feels like BukSU's Heyzine issues on desktop and phone.
- Reports are readable by anyone, with no account.

## Non-goals

- Editing or composing reports in CASDevU.
- Reports not tied to an activity (quarterly or semester issues).
- Comments, likes or view counts.
- Reports for draft activities.
- Using Heyzine or any outside flipbook service.

## Anti-goals (failure even if it technically works)

- A coordinator can upload, replace or delete a report for an activity they
  are not assigned to.
- The public Highlights page or its files expose anything private: draft
  activities, other uploads in `storage/`, or student documents. Public access
  must reach report PDFs only, through a page that serves exactly those.
- The flipbook is unusable on a phone, or so slow on a large PDF that readers
  give up. The first pages must appear quickly, with later pages loaded as
  they are reached.
- A failed or partial upload leaves a broken report posted.

## Constraints and things the builder must know

- **Uploads.** Use `store_upload()` (AGENTS rule 7), with PDF checked by
  extension **and** MIME type, as for requirements. Files are stored outside
  the web root and served by a new script that authorizes first. Here,
  authorizing means "this is a posted report of a published activity", since
  reading is public. `inventory/photo.php` and `requirements/download.php`
  show the existing pattern.
- **Server upload limits.** This XAMPP's `php.ini` has
  `upload_max_filesize=40M` and `post_max_size=40M`.
  - An upload over `post_max_size` arrives with an **empty** `$_POST`, so
    `csrf_verify()` would reject it as a bad token before any "file too
    large" message can be shown. The upload handler must detect that case
    (for example, a POST with a `CONTENT_LENGTH` over the limit and empty
    `$_POST`) and show a plain "This file is larger than X MB" message.
  - The form states the real effective limit: the lower of the setting and
    the server's limits.
- **Large files must open quickly.** The public PDF route must support HTTP
  Range requests, so PDF.js can fetch the first pages without downloading the
  whole file. The existing download scripts (`requirements/download.php`,
  `inventory/photo.php`) send whole files, so this is new.
- **No image tools on the server.** This PHP has no GD or Imagick, and there
  is no Ghostscript. Covers and pages are drawn in the reader's browser by
  PDF.js; nothing is converted on the server.
- **Viewer libraries.** PDF.js (Mozilla, Apache-2.0) renders the pages. A
  page-flip library such as StPageFlip (MIT) gives the curl. Both are
  self-hosted under `public/assets/` and linked with `asset()`, with no build
  step and no CDN. Ship their licence files.
- **Public pages.** The Highlights list, the viewer and the PDF route are the
  first public pages beyond sign-in and the item 2 invitation form. They need
  the same care: `e()` on every title, no data from draft activities, and
  `abort_page()` for anything missing.
- **`docs/03-role-matrix.md`** gains:
  - "Upload / replace / delete a highlight report": coordinator ◐ (assigned
    activities), the office ✔, student ✘, Guest ✘;
  - "Read highlight reports": everyone, including without signing in.

  `bin/smoke.php` must list the new pages, including a signed-out request.
- **The design system** (`DESIGN.md`, `style.css`): the cover grid and viewer
  frame use existing components and tokens. The flipbook stage is the one
  place where a dark field behind the pages is expected.
- **Item 7** (remove the staff role) is undecided. "The office" here means
  whichever role the office ends up with.

## Left to the builder

- Where the upload form lives (the activity's manage page is the obvious
  place).
- How the cover thumbnail is produced. With no image tools on the server, it
  is either rendered in the browser on the grid, or rendered once in the
  uploader's browser at upload time and saved as an image.
- Cleaning up the file when an activity is deleted.
- The page-flip library, if StPageFlip proves unsuitable.
- Keyboard and screen-reader behaviour of the viewer. It must at least turn
  pages with the arrow keys and keep the Download PDF link reachable as the
  accessible way to read the report.
- Exact copy, in the existing plain, official voice.

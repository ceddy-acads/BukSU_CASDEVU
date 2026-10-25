# Profile Photo Privacy Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop any signed-in user from downloading anyone else's profile photo by trying ids one by one.

**Architecture:** `public/profile-photo.php` currently serves any user's photo to anyone signed in. Only two places show a profile photo, and both show the viewer's **own**: `includes/layout/header.php:199` and `public/profile.php:134`. So the rule is: your own photo, or the office. Everyone else gets the same 404 as a missing photo, so the response does not reveal whether a photo exists.

**Tech Stack:** Native PHP 8.1+.

**Spec:** `docs/feature-backlog.md`, item 8. The backlog's wider rule (coordinators see their participants' photos) is not needed today: no page shows them. Add it if a page ever does.

## Global Constraints

- `AGENTS.md` rules hold. Lint every touched file. `bin/smoke.php` stays green.
- Build on top of `feature/student-borrowing` (item 1), in the same session, after item 1 is merged or on its branch. `bin/smoke.php` is touched by both.

## Review Focus

1. **A student requesting another student's photo id:** 404, the same as a missing photo. Pinned by the smoke check below.
2. **The office opening any user's photo:** 200, so future staff screens keep working.
3. **The header avatar still loading for every role:** smoke already requests `profile-photo.php` with `own_user` for all four roles.

---

### Task 1: Restrict `profile-photo.php`

**Files:**
- Modify: `public/profile-photo.php`
- Modify: `bin/smoke.php`

- [ ] **Step 1: Write the failing check.** In `bin/smoke.php`, add a negative check: signed in as the student, request `profile-photo.php?id=<another user with a photo>`, and expect 404. Use the coordinator's own id, or any id whose `photo_path` is not NULL; skip with a note if no other user has a photo in the demo data. Follow the style of the existing per-role loop near line 598.
- [ ] **Step 2:** Run `C:\xampp\php\php.exe bin\smoke.php --only=profile-photo`. Expected: FAIL (200).
- [ ] **Step 3:** In `profile-photo.php`, right after `$userId = get_id('id');`, allow only when `$userId === current_user_id() || is_office_staff()`. Otherwise answer exactly like a missing photo: `http_response_code(404); exit;`. Do not put the user id in the headers.
- [ ] **Step 4:** Run the smoke check again. Expected: PASS. Then the full `bin/smoke.php`: green. Then `php -l public/profile-photo.php`.
- [ ] **Step 5:** Update `docs/feature-backlog.md`, item 8, to "Built" with the rule as implemented, and add a row "View another user's profile photo: the office ✔, others ✘" to `docs/03-role-matrix.md`.
- [ ] **Step 6:** Commit: `Serve profile photos only to their owner and the office`

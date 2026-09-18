# Portal 3.11.0 — company dashboards and SEO client directory

## Installation

1. Update the existing WebNique Client Portal plugin with `webnique-client-portal-3.11.0.zip` through WordPress Plugins → Add New → Upload Plugin → replace the installed version.
2. Extract `facebook-companion-1.2.0.zip`. Replace the files in the existing unpacked Facebook extension folder, then Reload that extension in Chrome. Alternatively load the extracted folder containing `manifest.json` as unpacked; avoid leaving two copies enabled.
3. Refresh WordPress. Facebook Groups → Check connection should show companion 1.2.0. The Google Maps companion does not need updating.
4. Open Facebook Groups from the menu for the company dashboard. Edit each company's saved campaign, then Start an individual row or Start all ready. Resume enabled attaches this browser page to already-enabled schedules.
5. In SEO OS → API Management, select Estefania Erys Creative, enter `https://eryscreative.com/`, and generate its agent key. Existing keys are preserved. No key was generated during development.

The packages have been built and tested locally; they have not been installed on the live hub.

## Facebook behavior

- Multiple company schedules can be enabled at the same time. One dashboard takes turns through them in one browser tab; a server-side atomic lease prevents concurrent dispatch from other tabs.
- Interval is 60 seconds after each result. Loading, execution, schedule windows and Chrome throttling can extend it. A lost response retains a conservative 180-second lease from dispatch authorization.
- Final controls recognize Post, Publish, and a verified Next → Post settings / Review post → Publish flow. Existing drafts, incorrect text, missing attachments, expiry and identity mismatches block submission.
- Failed or unconfirmed attempts are terminal skips. Existing unknown and expired reserved records display as skipped; there is no internal manual submission-review queue.
- Confirmed public submission and confirmed Facebook moderation submission remain distinct. This update does not remove Facebook's own group moderation or security checks.
- Pause All stops scheduling and requests cancellation before the final click. An already-clicked post cannot be recalled. Closing the dashboard stops its browser runner; this is not an unattended server scheduler.
- Saved content, client isolation, daily limits, cutoff, rolling group exclusions and weekly duplicate protection remain in place.

## Lead Finder

Shared dark dashboard styling with compact tables, green status indicators and contrasting controls. Search tasks show each ZIP's real queue/collection/recovery/completion state and retained saved/email counts for newly completed searches. Existing collection, ZIP history, deduplication, lead list and GHL behavior remain intact.

## Erys / SEO OS cause

Erys exists as a legacy Analytics identity (`estefania-erys-creative`) without a shared client/billing profile. Analytics included this record; SEO OS previously read only shared client profiles. SEO now reads a common view of shared profiles plus active Analytics records without fabricating billing/contact fields or rewriting client IDs. New shared clients also appear in the same SEO directory. Completing Erys's billing profile remains optional and requires its actual contact/billing details.

See `seo-client-directory-3.10.1.md` for the underlying diagnosis and identity handling; that fix is included in this combined 3.11.0 release.

## Validation

- Changed PHP files linted; changed JavaScript parsed; git whitespace check.
- SEO directory/key-generation fixtures, shared-client directory, schema, Blog Scheduler regression.
- Facebook backend queue, 60-second cooldown, cross-company dispatch exclusion, client isolation, image persistence and group plan fixtures.
- Browser fixtures for Post, Publish and Next/Publish; cancellation, drafts, images, origin and duplicate protection.
- Dashboard tests for round-robin scheduling, skip-and-continue, one active publisher, search, Pause All and late Start responses, desktop/mobile layout.
- Lead Finder intake, list rendering, WordPress response handling and 3,647 browser/collector/UI assertions including per-ZIP task results and responsive layout.
- A duplicate helper definition in the pre-existing lead-list test harness was removed so its regression suite can execute.

All posting and collection tests use mocked pages/APIs. No Facebook post, group join, lead outreach, billing change or production API key was made during validation. Current live Facebook layouts may still require additional selector adjustments.

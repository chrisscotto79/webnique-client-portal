# Blog Scheduler audit — 3.8.4

## Fixes

- Queue previously showed only the first 50 client posts with no way to access
  later entries. Added client-scoped totals, pagination and stable ordering.
- Single-post add/edit could accept invalid dates. Strict calendar validation now
  matches bulk import behavior; invalid bulk dates are rejected instead of silently
  creating unscheduled entries. Blank dates remain unscheduled drafts.
- Validate selected active agent belongs to the client before add/import/edit.
- Changing the destination of an unpublished draft clears generated content as
  title/keyword changes already did, preventing stale site-specific content reuse.
- Require HTTPS and explicit TLS verification before sending agent credentials.
  No redirects. Raw remote response/error bodies are no longer shown or stored.
- New cron registration calculates 8am using the WordPress timezone, including
  today when it is before 8am, rather than PHP/server timezone and always tomorrow.

## Operator/SEO improvements

Queue shows portal timezone, actual next check, unscheduled/overdue labels and
per-draft SEO review: missing topic, description, H2 structure, multiple H1s,
missing inline image alt attributes, absent contextual links and missing selected
featured image. These are advisory; they neither rewrite copy nor block publishing.
Review factual claims, originality, intent and relevant images manually.

References: Google Search Central
https://developers.google.com/search/docs/appearance/title-link
https://developers.google.com/search/docs/appearance/snippet
https://developers.google.com/search/docs/appearance/google-images

## Remaining limitations / deployment checks

This is a targeted local audit, not a claim that all code is bug-free. No live
AI calls, client posts, dates or remote settings were changed in testing.
Run one controlled draft/publish test with the client agent after deployment.
Confirm actual title/meta/Elementor rendering, canonical URL, image relevance and
remote schedule-ID deduplication. Existing normalization strips body links; this
release surfaces the gap but does not invent URLs or change that content policy.

WP-Cron depends on traffic/server cron; daily intervals are fixed 24-hour periods
and may shift local wall time across DST. Existing registered events are preserved;
the UI reports their actual next timestamp. Same-day posts added after the daily
check may wait until the next daily run unless staff uses Publish Now. Exact-time
and per-client-timezone scheduling are not implemented by this release.

SEO checks examine the stored draft, not a live rendered page or ranking outcome.
The client agent may select a random media image when none is provided: choose a
relevant image explicitly. No automatic schema or unsupported business claims added.

Validation: PHP syntax across plugin, expanded offline Blog Scheduler regression
tests, review of diff/client scoping/credential responses. No live publishing.

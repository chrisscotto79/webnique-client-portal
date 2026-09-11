# Lead Finder 3.8.2 — bulk ZIPs and selected GHL handoff

## Install

Upload the zipped `webnique-client-portal` folder as the WordPress plugin update.
Reload the unpacked `browser-companion` extension at chrome://extensions (version
1.0.2), then refresh the WordPress Lead Finder page. No new permissions required.

## Bulk searches

Enter one niche and up to 100 five-digit ZIPs separated by commas, spaces,
semicolons or newlines. Click **Check ZIPs first**, review prior searches, and
click **Start selected ZIPs**. Duplicate ZIP inputs are removed. Leading zeros
are preserved. Previously searched ZIPs are unchecked; select them to rerun.

WordPress stores normalized keyword + ZIP, start/completion time (UTC), counts,
and limited-coverage status. Old imported lead notes can establish a prior search
but not completion; this legacy fallback checks the most recent 10,000 matching
lead records. New history has no such lookback cutoff. Empty-result runs are
recorded too. A completed run is not proof of exhaustive geographic coverage:
Maps can include nearby businesses, and each ZIP is capped at 100 listings.

ZIPs process sequentially. Keep Chrome and the WordPress tab open. Pause/resume
is supported. Remaining ZIPs are stored in this tab's session storage, not a
server background queue. Clearing browser session data loses the pending plan,
not saved leads/history. Interrupted runs remain marked started, not completed.

## GoHighLevel

The destination remains the existing configured location and the exact
**Land Clearing Cold Email** tag. Save the private integration token in the GHL
tab, then use **Test connection & tag** (read-only). The test now distinguishes
malformed responses from a missing tag and identifies the destination location.
It does not silently create tags or change workflows.

In Lead List, select reviewed businesses (up to 50 per page), then choose
**Approve & queue selected for GHL**. Confirming can trigger live emails when the
background worker applies the tag. Connection/tag preflight must succeed before
bulk queueing. Existing email deduplication, contact reuse, DND/unsubscribe checks,
suppression, uncertain-write reconciliation and automatic-mode controls remain.
Existing contact tags are preserved. No workflow API permission is needed.

Queue processing depends on WordPress cron. Queued is not sent; sent means the
tag is present, not that an email was delivered. First test with a contact you
control. No live contacts were enrolled during development validation.

## Validation

PHP syntax validation; history regression tests; browser intake regressions;
GHL mocked contact/tag/suppression/bulk regressions; companion/collector and
bulk UI tests; late-handshake recovery; responsive GHL UI tests; diff checks.
Live Maps DOM changes, real hosting/cron, installed database migration and the
private GHL token/location permissions still require a deployment smoke test.

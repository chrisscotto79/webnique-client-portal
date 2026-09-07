# Client Analytics lead summary — 3.7.0

Client Analytics now opens with a read-only Lead Summary for the selected date range. The primary number is **Total Verified Leads**: unique Google Ads calls at or above the configured duration threshold plus GA4 form and email events that are marked as key events. Website phone clicks are shown separately and are never added to the lead total.

The same Client Analytics area also includes a Google Business Profile panel for the client's saved GBP location mapping. It reports profile views, Search and Maps views, website clicks, profile call clicks, direction requests, and a daily evidence table for the selected period. GBP profile call clicks are interactions—not confirmed Google Ads calls—and remain separate from lead totals.

The summary also shows All Recorded Calls versus Verified Calls, a client-facing sentence, provider status, and an expandable Tracking Breakdown for Ads calls, GA4 paid/organic/other/unknown phone clicks, forms, and emails. A warning is shown when paid phone clicks cannot be confidently matched to the saved Ads customer ID. Each provider remains independent, so an unavailable GA4 property does not hide available Ads call counts.

## Configuration

Expand **Tracking connections & lead-event names** in Client Analytics. Select the existing portal client with the saved Google Ads account, enter the exact GA4 event names for phone clicks, form leads, and email leads, and set the minimum call duration. The default (and SNS Hauling rule) is 20 seconds. Saving these values does not install tags, change Ads settings, or enable writes.

## Boundaries

- Search-only Google Ads `call_view` data is used for verified calls. The overview endpoint now returns account/campaign cost and currency by request (3.7.1); credentials, tokens, billing details and recordings remain excluded.
- Calls are deduplicated by the account-scoped `call_view` resource name. Phone clicks, forms, and emails remain separate evidence streams to prevent call/click double-counting.
- GA4 form/email counts require staff confirmation that these events fire for completed submissions, plus GA4 key-event reporting. They are not CRM-person deduplication. See ANALYTICS-QA-3.7.1.md for audit changes and limitations.
- Date filters are applied to every provider. Reports are cached briefly per client, provider, date range, mapping, and configuration; Refresh bypasses the cache.
- Staff permissions, nonces, exact client mapping, and server-side credential handling are preserved. No Google Ads mutation endpoint was added.

## Tests

`tests/analytics-lead-summary.php` checks event classification, key-event-only lead counting, and attribution safeguards. `tests/analytics-activity-browser.cjs` exercises the production renderer at desktop and mobile widths, including the primary total, period sentence, warning, expandable breakdown, filters, pagination, escaping, refresh, and overflow checks. Run PHP syntax validation across all plugin files and both fixtures before release. These are offline checks; verify one connected client on staging for live GA4/Ads reporting behavior.

## Provider references

- [Google Ads call_view fields](https://developers.google.com/google-ads/api/fields/v25/call_view)
- [GA4 reporting dimensions and metrics](https://developers.google.com/analytics/devguides/reporting/data/v1/api-schema)
- [Google Business Profile Performance API](https://developers.google.com/my-business/reference/performance/rest/v1/locations/fetchMultiDailyMetricsTimeSeries)

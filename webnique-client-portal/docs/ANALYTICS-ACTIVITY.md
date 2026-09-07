# Client results activity — 3.6.0

## Included

The existing backend Analytics tab now starts with three independently loaded reports above the preserved GA4, Search Console, and Ads overview:

1. **Google Ads call records:** Search-campaign call start timestamps, campaign, call status, duration, and account timezone. Uses the existing exact client-to-Ads mapping and read-only GoogleAdsQueryService. No recordings, caller numbers, spend, cost, CPC, or billing are returned.
2. **GA4 phone-click activity:** date/minute, event name, device, reported session channel, event count, and GA4 key-event count. Sources are Google Ads, Organic search, Other, and Unknown. Google Ads requires the session Ads customer ID to match the saved client account. Paid Search alone does not establish that match. Event names are configurable per client; default is the existing phone_click.

Each panel has reporting dates, timezone, explicit limits, empty/unavailable states, source filtering, text search, and 25-row pagination. Phone-click source totals count events in the fetched report, not unique people. There is deliberately no combined lead total: phone clicks and Ads calls can overlap.

## Setup

In Analytics, select a client and expand **Tracking connections & phone-event names**.

- Enter exact GA4 event names already sent by that client's website. Saving a name does not install a tag or mark an event as a key event.
- Save, then Refresh. Not connected, unavailable, and empty are distinct states.

## Safety and boundaries

- New activity endpoint is logged-in backend staff-only (manage_options or wnq_manage_portal) with the Analytics nonce. It does not expand the existing client-facing AJAX response.
- Setup requires the same role checks, a client-specific nonce, and an existing analytics client.
- Reports cache for three minutes under client/provider/date/configuration identities. Refresh bypasses report cache. Browser responses use no-cache headers.
- No Google Ads mutation endpoint, public webhook, tracking tag installation, or automatic account matching was added.
- Source failures do not erase another provider's results. API failures remain scoped to that provider.

## Reporting limitations

- GA4 Data API returns aggregated minute-level rows, not raw individual call events or recordings. Privacy thresholds, sampling, attribution, and delayed processing can affect results. Restrictions are surfaced when reported in metadata.
- This call_view feed covers Search ad call reporting, not every website call. Missing records do not prove no calls occurred. Recordings are not provided by this feed.
- Form date filters fetch a one-day buffer and enforce the displayed WordPress timezone dates locally, because the upstream date filter timezone is unspecified.
- Maximum fetched records: 1,000 GA4 groups and 1,000 Ads calls. Capped/limited reports are labeled incomplete. These are not unlimited full-history exports.
- Phone-click event names must be dedicated to phone actions; configuring a generic click event would also count unrelated clicks.
- Per-client connection setup is manual and explicit. No real credentials or subaccount IDs were populated during development.

## Tests

- analytics-activity-regression.php: mapping/attribution safeguards, authenticated credential storage, settings validation, GA4 row/time normalization, threshold disclosure, Search-only call query, call-account mismatch rejection, GHL paging/date conversion/field exclusion, failed-page behavior, staff permissions, disconnect, and Ads independence from GA4.
- analytics-activity-browser.cjs: production renderer and styles with synthetic responses at 1440px and 390px; source filters, pagination, search, escaped content, unavailable GHL state, refresh, and no page overflow.
- Existing Analytics, PPC, and service-city regressions remain part of release validation.

These are offline tests, not live WordPress/Google/GHL acceptance tests. Check one real client end-to-end on staging before rollout.

## Provider references

- [Google Ads call_view fields](https://developers.google.com/google-ads/api/fields/v25/call_view)
- [GA4 reporting dimensions and metrics](https://developers.google.com/analytics/devguides/reporting/data/v1/api-schema)

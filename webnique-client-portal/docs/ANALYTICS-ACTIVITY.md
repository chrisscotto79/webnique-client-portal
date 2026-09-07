# Client results activity — 3.6.0

## Included

The existing backend Analytics tab now starts with three independently loaded reports above the preserved GA4, Search Console, and Ads overview:

1. **Google Ads call records:** Search-campaign call start timestamps, campaign, call status, duration, and account timezone. Uses the existing exact client-to-Ads mapping and read-only GoogleAdsQueryService. No recordings, caller numbers, spend, cost, CPC, or billing are returned.
2. **GA4 phone-click activity:** date/minute, event name, device, reported session channel, event count, and GA4 key-event count. Sources are Google Ads, Organic search, Other, and Unknown. Google Ads requires the session Ads customer ID to match the saved client account. Paid Search alone does not establish that match. Event names are configurable per client; default is the existing phone_click.
3. **GoHighLevel form arrivals:** submission arrival date/time and form ID only, with no contact details or form answers imported. Attribution stays Unknown because this version does not verify submission-specific acquisition metadata.

Each panel has reporting dates, timezone, explicit limits, empty/unavailable states, source filtering, text search, and 25-row pagination. Phone-click source totals count events in the fetched report, not unique people. There is deliberately no combined lead total: phone clicks and Ads calls can overlap.

## Setup

In Analytics, select a client and expand **Tracking connections & phone-event names**.

- Enter exact GA4 event names already sent by that client's website. Saving a name does not install a tag or mark an event as a key event.
- Enter that client's GoHighLevel Location ID and a subaccount Private Integration Token with forms read access. Verify the Location ID belongs to the selected client before saving.
- Leave a saved token blank to retain it. Changing Location ID requires a new token; disconnect clears the mapping and token.
- Save, then Refresh. Not connected, unavailable, and empty are distinct states.

GoHighLevel API requests use its documented v3 Version header, locationId query scope, and page-based forms/submissions endpoint. Staging verification is required against the user's actual subaccounts and scopes.

## Safety and boundaries

- New activity endpoint is logged-in backend staff-only (manage_options or wnq_manage_portal) with the Analytics nonce. It does not expand the existing client-facing AJAX response.
- Setup requires the same role checks, a client-specific nonce, and an existing analytics client.
- GHL credentials use AES-256-GCM with WordPress site salts and authenticated client/location binding. Tokens are never prefilled, returned, logged, or included in report payloads.
- GHL fetches use a fixed HTTPS endpoint, no redirects, bounded timeouts, and a narrowly shaped response. Only timestamp/form ID survive normalization.
- Reports cache for three minutes under client/provider/date/configuration identities. Refresh bypasses report cache. Browser responses use no-cache headers.
- No Google Ads or GoHighLevel mutation endpoint, public webhook, tracking tag installation, or automatic account matching was added.
- Source failures do not erase another provider's results. API failures after an earlier forms page fail the feed rather than presenting a successful partial report.

## Reporting limitations

- GA4 Data API returns aggregated minute-level rows, not raw individual call events or recordings. Privacy thresholds, sampling, attribution, and delayed processing can affect results. Restrictions are surfaced when reported in metadata.
- This call_view feed covers Search ad call reporting, not every website call. Missing records do not prove no calls occurred. Recordings are not provided by this feed.
- Form date filters fetch a one-day buffer and enforce the displayed WordPress timezone dates locally, because the upstream date filter timezone is unspecified.
- Maximum fetched records: 1,000 GA4 groups, 1,000 Ads calls, 500 GHL submissions (five pages). Capped/limited reports are labeled incomplete. These are not unlimited full-history exports.
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
- [GoHighLevel form submissions](https://marketplace.gohighlevel.com/docs/ghl/forms/get-forms-submissions/)
- [GoHighLevel private integrations](https://marketplace.gohighlevel.com/docs/Authorization/PrivateIntegrationsToken/index.html)

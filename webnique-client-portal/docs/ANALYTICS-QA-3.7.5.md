# Recent lead activity — 3.7.5

A newest-first activity table appears immediately after Lead Summary. It shows Google Ads Search call records, generate_lead GA4 key events, and configured email event interactions (including events without a key-event count). Phone clicks are excluded from this timeline to avoid representing the same call twice. Existing lead totals are unchanged.

The feed respects Analytics date filters, displays portal-timezone timestamps with Today/Yesterday labels, and supports source filtering, text search and 25-row pagination. Calls retain their duration, status, campaign and qualification label. GA4 entries contain minute, device, source and aggregated count; no customer details, actual email contents or submitted form contents are available from this report.

Each provider is handled independently and unavailable/partial sources are identified. Source queries are capped at 1,000 rows and GA4 reporting limitations are surfaced. Google reporting can be delayed. Date bounds use source reporting calendars, while displayed timestamps use the portal timezone. Unknown timestamps/timezones result in incomplete coverage, not guessed ordering.

The staff-only endpoint retains nonce, permissions, client mapping and a short cache. No credentials or tokens are returned. GBP remains removed. Syntax, timeline aggregation, timezone conversion, sorting, provider failures, browser rendering and existing Analytics regressions were tested using offline fixtures; live provider reconciliation remains unverified.

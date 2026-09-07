# Analytics tab QA — 3.5.2

## Confirmed defects fixed

- Inclusive reporting windows previously fetched N+1 days. Last 7/30/etc. days now contains exactly N dates, including today, and the requested dates are displayed.
- Concurrent requests could overwrite a newly selected reporting range. Older requests are aborted and stale callbacks ignored.
- Shared GA4 token caching could reuse the previous service-account identity after credential rotation. Analytics tab tokens are now keyed by email and private-key fingerprint; clearing cache also removes the current scoped token.
- Analytics configuration errors could prevent Ads from loading before provider-specific handling began. Configuration failure now leaves Ads independently available.
- Search Console refresh previously reused cached data. Explicit refresh bypasses its report cache; normal requests remain cached.
- Traffic-source percentages used only the top ten channel rows as their denominator. They now use the overview's total sessions for the same dates.
- Top pages were split by path and title, allowing multiple entries for a path when its title changed. Reports now group by path.
- GA4 requests validate property IDs, status codes, and response shape; malformed responses produce an unavailable state.
- Error text inserted into the page is escaped.
- Existing action counters are labeled “Tracked Actions,” with a note that they are selected event counts, not necessarily configured GA4 key events or unique leads.
- Charts are disposed of before replacing the dashboard, including when the next report has no chart.

## Verification

Run:

```sh
php tests/analytics-regression.php
node tests/analytics-ui-regression.cjs
php tests/ppc-quality-regression.php
php tests/service-city-blueprint-regression.php
```

Analytics tests use offline WordPress/API doubles. They cover date boundaries, account scoping, Ads independence, cost/credential exclusion, token-cache identity, malformed reports, channel denominators, page grouping, and GSC refresh behavior. The UI test runs the real inline controller with a jQuery double and exercises out-of-order responses and HTML escaping.

## Preserved behavior and remaining work

GA4, GSC, and Google Ads remain separate providers. Existing permissions/nonces and saved account mappings are retained. Google Ads remains read-only, and this Analytics endpoint does not return spend, cost, CPC, billing, or credentials.

No live account or installed WordPress browser session was tested. Verify on staging with the actual selected client's properties. Today remains partial; provider reporting timezones and reporting delays may differ. The tracked-action list is still a fixed set of existing event names. This pass does not install tags, prove events fire, deduplicate leads, or add date/device drilldowns.

Next tracking audit should check actual website event firing and consent behavior, configured GA4 key events, duplicate tags, phone/form success definitions, and whether campaign attribution survives the conversion flow. Do not equate a button click with a confirmed lead.

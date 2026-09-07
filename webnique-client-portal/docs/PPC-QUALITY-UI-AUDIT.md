# PPC quality and usability audit — 3.5.1

## Scope

This maintenance release reviews the existing plugin, fixes confirmed PPC defects, and improves the Search intelligence workspace. It does not add campaign types or Google Ads write operations. All edits stay inside the uploadable plugin folder.

## Corrected defects

- Google Ads REST reports now follow page tokens instead of silently stopping at the first page. Repeated tokens, invalid JSON, or a failed later page fail the report rather than returning incomplete success. Requests remain tied to the original customer ID. A 20-page safety bound fails closed.
- API error text redacts configured secrets and bearer tokens.
- Search-term queries explicitly filter to Search campaigns. The existing 2,000-row cap is now disclosed as partial coverage.
- Proposal identity and learned feedback include the ad group, avoiding cross-route collisions. Bulk review selects the current client's exact linked account and saves review status plus feedback in one transaction; any failure rolls the batch back.
- Feedback preserves the original classification separately from human corrections. Explicit exclusions, matching client rules, and unavailable memory retain human review requirements.
- Memory database errors no longer look like a healthy empty history.
- Expired mutation previews now return their effective expired status and decoded evidence correctly. Current-account plan/recommendation lists and portfolio snapshots no longer mix records from a previously linked account.
- Negative-conflict deduplication includes campaign/ad-group identity, preserving distinct conflicts when criterion IDs repeat.
- Anomaly detection requires supported historical weeks, still detects count drops to zero, excludes undefined rates, and distinguishes flat baselines from calculated z-scores.
- Anomaly impression-share aggregation uses estimated eligible impressions; censored or missing shares are not treated as exact observations.
- N-gram support counts distinct queries, not duplicate campaign/ad-group rows. Pattern totals remain overlapping. Undefined advanced CPA/conversion rates display as unavailable, not zero.
- Messaging opportunities use same-ad-group metrics and only enabled RSAs in enabled campaigns/ad groups. Matching cannot cross separate RSA assets or unrelated claim records. A theme source is evidence for review, not approval of newly generated factual copy.
- Incomplete sources propagate into advanced module/investigation status. Empty findings from failed sources do not yield a healthy account. Negative conflicts and URL checks remain investigations, not confirmed root causes.

## Interface improvements

- Focused workspaces: Overview, Performance, Search & creative, Lead quality, Review & memory.
- Keyword quality lives with performance; durable history lives with review controls.
- Evidence links open the relevant workspace. Report navigation includes change history, correlations, and validation.
- Advanced tables show fuller metrics, source links, example queries, reporting periods, record counts, and clear empty/partial states.
- Local search, 10/25/50/100-row pages, visible ranges, keyboard-focusable table scrolling, and no hidden 100-row display cap.
- Filtering/pagination clears hidden selections so bulk review cannot accidentally act on hidden rows.
- Responsive navigation, bounded scrolling, sticky table headers, contrast/focus improvements, and retained Golden Web Marketing colors.
- Asset version bumped to 3.5.1 to refresh browser caches. Changed search/anomaly/quality report caches use a new namespace.

## Verification

- PHP syntax validation: all 106 PHP files passed.
- JavaScript syntax validation: all 17 JS/CJS files passed.
- Existing PPC regression suite passed.
- Service-city blueprint regression suite passed.
- New offline quality suite passed: API pagination/failure/redaction, account/route isolation, review rollback, original classification, client-rule guards, preview expiry, incomplete investigations, negative-conflict identity, anomaly edge cases, n-gram support, Quality Score safeguards, and RSA matching.
- Browser fixture checks passed at 1440px and 390px: workspace navigation, keyboard tabs, filtering/no-results, pagination beyond row 100, escaping, independent unavailable state, and no document-level horizontal overflow. Desktop/mobile screenshots were visually reviewed.
- Diff whitespace review passed. Production changes contain no added credentials, tokens, or mutation endpoint.
- Existing Analytics response continues to allowlist clicks, impressions, CTR, conversions, and safe campaign fields. No spend/cost/CPC/billing fields were added there.

Run locally:

```sh
php tests/ppc-phase1-regression.php
php tests/ppc-quality-regression.php
php tests/service-city-blueprint-regression.php
node tests/ppc-ui-regression.cjs
```

The browser test requires Puppeteer and its Chrome runtime; it creates temporary synthetic fixtures/screenshots. New fixture scripts run only from the command line and are not production data.

## Preserved calculations and limitations

Existing account performance, budget pacing, conversion-health, lead-quality, and validation formulas were not changed. The explicit mathematical corrections are in the advanced anomaly, n-gram, and messaging modules described above. More complete API pagination can legitimately increase totals previously based on incomplete rows.

This is not a certification that every plugin path is bug-free. No live WordPress/MySQL instance, production Google Ads account, real OAuth failure, or deployed theme/plugin combination was exercised. Transaction tests use a database double; staging must verify transactional tables, concurrent reviews, and real database migrations.

Still needed on staging: compare a selected client's displayed customer ID with Reports/Telegram, compare reporting windows with Google Ads in the account's timezone, verify nonce/role enforcement with real users, exercise expired previews and account remapping, and check a large real account. Existing reporting windows mostly use WordPress timezone; advanced anomaly detection does not model conversion lag, holidays, or long-term seasonality.

Search-term privacy omissions and the disclosed 2,000-row cap limit downstream pattern/routing/messaging coverage. Some older inventory queries retain their existing limits. English-oriented literal matching is conservative, not semantic intent understanding. Quality priority remains a heuristic, not a causal or profitability model. Wide evidence tables intentionally scroll horizontally on phones.

No Ads execution or rollback endpoint was enabled. Human review and approved previews are internal records only. The broader mutation approval/audit/rollback execution architecture remains out of scope.

# Phase 12: Search PPC intelligence

Phase 12 is limited to Google Search campaigns and remains completely read-only.

## Added

- Campaign anomaly investigations compare the last seven complete days with four prior campaign-specific seven-day baselines. A metric must move at least 30%, exceed 2.5 baseline standard deviations, and pass a metric-specific traffic/spend safeguard.
- Search-term n-grams aggregate recurring one-, two-, and three-word patterns across impressions, clicks, cost, conversions, conversion rate, and CPA. Waste and high-performing labels create review evidence only.
- Persistent client memory stores dated rules, strategies, bid-strategy history, patterns, tests, outcomes, and learnings against the exact client/customer pair.
- Human search-term decisions store the original AI classification, decision, reason, reviewer, date, and optional eventual result. Exact feedback older than 365 days remains visible but stops overriding current classification.
- Search-to-RSA messaging gaps compare converted recurring themes with active RSA copy. Suggested messaging is allowed only when the theme is present in approved client-domain evidence; otherwise it remains blocked for claim verification.
- Query-routing investigations compare the triggered route with enabled keyword coverage in other ad groups/campaigns and include available negative/rule context.
- Quality Score intelligence reads Quality Score, Expected CTR, Ad Relevance, and Landing Page Experience and ranks only below-average components with meaningful economic/traffic evidence.

Every module has its own unavailable state and 15-minute data cache where it makes a Google Ads request. Existing search-term, keyword, RSA, recommendation, investigation, and account-mapping services are reused.

## Safety boundaries

- Every new Google Ads query explicitly filters `campaign.advertising_channel_type = 'SEARCH'`.
- No module sends a mutate request, adds negatives, changes ads, changes bids, or changes budgets.
- Stored memory and feedback are server-side and scoped by both internal client ID and exact ten-digit Google Ads customer ID.
- Admin writes require the existing PPC capability and client-specific WordPress nonces.
- Credentials and tokens are never included in module results or rendered HTML.

## Current limitations

- Anomaly detection uses four weekly baselines rather than a seasonal forecasting model; holidays, promotions, and major market shifts still require human interpretation.
- Search-term reporting is limited to terms Google Ads makes available and the existing 2,000-row query cap.
- N-grams are contiguous literal tokens and do not yet perform semantic clustering or stemming.
- Routing uses explicit enabled keyword coverage and text similarity; it does not claim Google auction routing causality.
- Messaging verification requires an exact normalized theme in the existing approved website/claim evidence. It does not generate finished ad copy.
- Quality components may be absent when Google has insufficient keyword data.
- Persistent client rules are intentionally not auto-executed. They are surfaced explicitly for human interpretation.

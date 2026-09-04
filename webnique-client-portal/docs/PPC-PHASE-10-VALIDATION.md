# PPC Intelligence Phase 10

Phase 10 makes recommendations measurable and auditable while keeping Google Ads read-only. It extends the Phase 9 workspaces instead of replacing them.

## Data and API resources

All Google Ads requests continue through `GoogleAdsQueryService`, which accepts `SELECT` queries only.

- Direct negatives: `ad_group_criterion` and `campaign_criterion`, including resource/criterion ID, keyword text, match type, campaign, and ad group.
- Positive-keyword safeguard: current enabled Search `keyword_view` criteria are re-read before negative proposal/preview decisions.
- Campaign shared lists: `campaign_shared_set` plus attributed `shared_set` ID, resource name, name, type, status, and attached campaign.
- Account-level negative lists: `customer_negative_criterion.negative_keyword_list.shared_set` plus attributed shared-set identity where accessible.
- Shared-list contents: `shared_criterion.shared_set`, resource name, keyword text, and keyword match type. Manager-owned list resources are read from their resource owner when accessible through the configured manager connection.
- Validation: campaign status and aggregate cost, impressions, clicks, conversions, conversion value, Search impression share, lost Search impression share to budget, and lost Search impression share to rank for equal-length periods.
- Correlation: daily campaign cost, clicks, impressions, and conversions, paired with recent Change Event campaign/ad-group identity and changed fields.
- Shared budgets: campaign-budget ID, name, resource name, amount, shared flag, attached campaigns, and their aggregate month-to-date performance.

Google Ads Change Event history is recent-history evidence and is not a permanent change ledger. A missing or inaccessible negative scope is reported as unavailable and is never treated as an empty list.

## Database schema

`{prefix}wnq_ppc_recommendations` stores the stable UUID/key, exact client and Google Ads customer mapping, entity scope, category, recommendation/evidence, separate confidence values, reporting period, lifecycle state, implementation metadata, validation evidence, outcome, and review timestamps.

`{prefix}wnq_ppc_recommendation_events` is an append-only lifecycle audit table. Each event stores the recommendation, transition, actor, note, metadata, timestamp, previous hash, and current event hash.

Tables are installed or upgraded through `PpcRecommendation::maybeUpgrade()` and WordPress `dbDelta()`.

## Lifecycle states

Open, Investigating, Ready for review, Approved, Rejected, Implemented externally, Implemented through system (reserved for a future phase), Monitoring, Successful, Neutral/inconclusive, Unsuccessful, Superseded, and Cancelled.

Approval never implies execution. External implementation requires a separately recorded implementation date. Outcome labels require a previously documented implementation.

## Validation safeguards

- Uses equal 7/7, 14/14, and 30/30 complete-day periods around the documented implementation date.
- Waits until a complete after-period exists.
- Returns Insufficient data for sparse traffic/conversion samples.
- Flags material spend imbalance, relevant concurrent changes, and paused campaigns as confounders.
- Keeps Positive evidence, Neutral/inconclusive, Negative evidence, and Insufficient data separate from the human lifecycle outcome.
- Treats timing correlations as investigative evidence, never automatic causal proof.
- Does not fabricate GA4 evidence. Phase 10 makes no GA4 API or schema change; existing Phase 6 lead-quality reporting remains independent.

## Negative and budget safeguards

- Checks applicable ad-group, campaign, campaign-shared-list, and accessible account-level negative scopes before proposing an exact preview.
- Suppresses duplicates and requires human review for protected brand, service, and target-geography overlaps.
- A partial negative inventory blocks preview creation rather than assuming the unreadable scope is clear.
- Shared budgets are shown as grouped resources with all returned attached campaigns and aggregate month-to-date spend, projection, clicks, and conversions.
- No budget recommendation can execute, and shared-budget findings remain blocked for grouped review.

## Deliberate limitations

- Google Ads API availability and permissions determine whether manager-owned and account-level lists can be read.
- Change Event lookback is limited by Google Ads, so old concurrent changes may be unavailable.
- Before/after comparison is observational. It does not fully control for auctions, seasonality, attribution lag, offline sales, or unrecorded external changes.
- GA4 lead-quality stages are not yet joined to historical 7/14/30 recommendation windows.
- Specialized PMax, Display, YouTube, and Demand Gen intelligence remains out of scope.
- Every final recommendation decision, implementation record, and outcome label requires human review.

## Write capability

No Google Ads write capability was added. Phase 10 contains no execution or rollback endpoint. Existing Mutation Safety approvals remain local, expiring authorization records only.

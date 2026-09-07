# Client Analytics audit — 3.7.1

## Corrected reporting

- Google Ads cost is now explicitly allowed in Client Analytics at account and campaign level. Amounts use account currency from the API or saved account metadata; missing currency is disclosed instead of assuming USD. Cost in micros is converted once by the existing service. Existing CTR, CPA and conversion calculations are unchanged.
- Removed the 100-campaign query cap and five-campaign display cap. Account sums now use all successfully paginated campaigns. The existing pagination guard fails rather than returning an incomplete total.
- GBP now requests the documented desktop/mobile Maps metrics. Search and Maps totals and daily rows combine both devices. Missing metrics remain unavailable. Invalid responses no longer become zero activity, and raw API errors are not returned to the browser.
- GBP cache identity includes the saved location and connection identity. Analytics can reuse its explicitly resolved portal client's saved GBP mapping.
- Added This Month and Previous Month alongside the existing rolling filters. Both Analytics endpoints use one date-window function.
- Removed the inaccurate “this month” wording for rolling/long periods and the claim that every form/email came from Google Ads.
- Verified lead totals require complete Ads and lead-event coverage. Sampled, capped or failed sources cannot generate a complete total. Website and GBP call clicks remain excluded.
- Form/email events must be confirmed by staff as completed submissions before entering verified totals. Marking an email-app click as a GA4 key event is not proof of a submitted email. Event names alone cannot establish this; review the site's tag setup before checking the setting.
- Lead totals request GA4 aggregates by event name, avoiding the previous 1,000-minute-row truncation. Activity detail retains its disclosed 1,000-row cap.
- GA4 authentication and Ads mapping failures are isolated. Ads calls can load during a GA4 token failure; phone events can load with uncertain Ads attribution.
- Display all returned traffic channels and the top ten pages. Summary cards reflow responsively, evidence tables start collapsed, and metric explanations work by keyboard or tap.

## Validation and limits

Offline tests cover date boundaries, duplicate call IDs, the 19/20/21-second qualification boundary, click exclusion, source failures, incomplete coverage, confirmation safeguards, GBP device sums, missing GBP metrics, account isolation, cost/currency display and browser request races. Existing PPC regression checks and PHP syntax checks are also run.

Live WordPress, Google Ads, GA4 and GBP data were not available for account-to-account reconciliation in this development environment. Before using numbers in client reporting, compare a connected client's selected dates and provider time zones against Google and confirm submission-event semantics. Calls are unique call records; leads count tracked submissions and are not deduplicated people across multiple submission types. Recent provider data can be delayed. GBP's supported historical window may be shorter than the dashboard's longest filters.

Reference: https://developers.google.com/my-business/reference/performance/rest/v1/DailyMetric

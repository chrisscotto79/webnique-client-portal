# Client Analytics 3.7.2

Google Business Profile has been removed from Client Analytics. The browser no longer requests it, and the activity endpoint rejects the retired provider even when a caller requests it directly. GBP Scheduler, its saved credentials and mappings remain untouched.

The lead summary now emphasizes Total Verified Leads and places Website Phone Clicks in a separate secondary area. Loading no longer briefly displays unavailable lead totals. Provider badges distinguish available, incomplete and unavailable reports. Partial phone-click evidence explicitly identifies its limited coverage. Search Console queries and Google Ads campaign tables are expandable to reduce scrolling.

GA4, Search Console, Google Ads cost, call thresholds, lead calculations, account mapping, date filters and provider isolation remain unchanged. No Google Ads writes were added.

Validation: PHP syntax, Analytics endpoint/provider rejection, existing Ads cost and lead-summary regressions, controller rendering, browser filtering/pagination/escaping, and desktop/mobile layout checks. Live Google accounts were not available for reconciliation; these changes use the existing data services without changing calculations.

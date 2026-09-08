# GA4 lead counts — 3.7.3

Form Leads and Email Leads now directly sum GA4 keyEvents for the client's configured form and email event names. The additional local confirmation checkbox has been removed, including its runtime gate. Existing clients need no settings migration or confirmation. The activity cache namespace has changed so old blocked summaries are not reused.

Successful reports with no matching key events show zero. Raw eventCount is not substituted for keyEvents. Phone events and overlapping form/email names remain excluded from double counting. Verified Ads calls retain their configured duration threshold. The existing total adds those calls to form/email key events; explanatory text clarifies that this is not a unique-person count.

Actual source failures and incomplete reporting retain unavailable/partial states. Clients still need the correct event names configured and marked as key events in GA4.

Validation covers absent/false legacy confirmation flags, key events versus raw events, zero rows, Ads and GA4 independent failures, partial coverage, date filters, browser rendering and PHP syntax. Live client GA4 responses were not available locally.

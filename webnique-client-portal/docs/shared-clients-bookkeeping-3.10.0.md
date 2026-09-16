# Shared clients and monthly bookkeeping — 3.10.0

## What changed

- Add a client once in Money Management. IDs can be generated automatically; optional GA4 and Search Console setup is on the same form. Advanced fields are collapsed. No WordPress user or portal login is created automatically.
- Corrected the existing client table's dbDelta migration syntax and removed SQL comments from the column definition. Schema version 5 is only marked applied after the required new columns exist. Duplicate contact emails and IDs now produce explicit errors. This is a code-level finding; the production database schema was not directly inspected.
- Analytics includes the existing shared client directory even before Google settings exist. Old Analytics records and IDs are preserved. Exact IDs win; a legacy configuration can be reused through a unique website match only when there is one portal owner and no contradictory GA4 property. Ambiguous records are not merged.
- Analytics-only records have a Complete shared client profile link. Supply the missing contact email/billing details there; the saved Analytics ID, website, event settings and credentials remain unchanged.
- SEO OS dashboard includes active clients without an SEO profile, labeled Setup needed. This does not start SEO publishing, grant Google permissions, or invent audit results.
- Google connection checks now make real report requests for the selected client. Missing properties, authentication failures, denied access and quotas have actionable messages. Google failures remain unavailable, never fabricated zero totals.
- Removed the finance graph fallback that copied current forecast revenue into twelve historical months. Money rows display cents so they add up to the cards.

## Automatic bookkeeping (owner requested; no Stripe integration)

Enabled by default for active monthly clients with a positive rate. Disable per client in Billing & Payments. Due day uses the configured day, then last payment day, then creation day; short months clamp to the last day. An inferred day is retained for later months. The task runs hourly through WordPress cron and also on opening Money Management. Reliable unattended timing requires the site's existing cron runner to operate; low-traffic WordPress sites may need a host cron.

Only the current month is assumed paid; installation does not invent historical receipts. An existing payment in the current month is preserved. Quarterly/annual accounts keep their existing manual flow. Records are labeled Assumed paid (unverified), not payment-processor confirmed. No Stripe connection or payment notification is triggered by this new bookkeeping service.

Paid entries use the saved after-fees amount, update payment count and total collected, and advance next due date. One finance row per client/month plus an InnoDB transaction and row lock prevent double counting. Mark paid can confirm an assumed entry without adding revenue twice. Mark unpaid reverses this month's new bookkeeping amount/count and keeps a permanent exception so cron cannot mark it paid again. Mark paid can restore that exception once. Pre-upgrade totals are preserved, not guessed/reconstructed. History remains in Income & Expenses; bookkeeping rows cannot be deleted there because doing so would break totals. Current-month adjustments use the client buttons.

The additive migration extends the existing finance table with nullable bookkeeping fields and a unique client/month key. Existing finance rows remain unchanged. Non-InnoDB storage fails closed with a visible error instead of risking partial financial writes. Existing client totals are not overwritten by stale profile forms.

## Live findings and rollout

Read-only inspection found Estefania Erys Creative in Analytics under `estefania-erys-creative`, but absent from Money Management's seven-client list. GA4 property `properties/554543484` and Search Console property `sc-domain:eryscreative.com` were saved. The browser's Search Console tab used the separate URL-prefix property `https://eryscreative.com/`. Actual API access failure cannot be confirmed from the old generic error alone.

After installing 3.10.0, refresh WordPress, complete Erys Creative's shared profile, then run Check this client's Google connections. Confirm the saved service account can access GA4 and the exact Search Console property. No live records, Google permissions or payments were changed during development.

## Verification

`tests/monthly-bookkeeping.php`: repeated cron, confirmation, reversal, manual exceptions, rollover, short months, disabled clients, transaction rollback, retry and legacy preservation.

`tests/shared-client-directory.php`: synthesized shared profiles, saved settings, exact IDs, safe legacy reuse, ambiguous mapping rejection and unknown IDs.

Existing Analytics calculation, cost, lead summary, recent activity and UI regression tests passed, along with Facebook client isolation tests. These are local mocked tests, not a production database migration or live Google authentication test.

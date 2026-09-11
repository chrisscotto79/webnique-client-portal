# Lead Finder GHL approval — 3.8.10

Manual selected/individual approval now explicitly overrides review-count, company-type and SEO qualification. An audited `override` queue mode preserves that approval through both worker eligibility checks. Automatic imports and previously queued ordinary manual jobs retain their existing qualification rules.

Lead List adds **Send all valid companies to GHL**. After confirmation it scans the entire saved list (not current filters or page), in 500-ID chunks with a fixed upper ID. It queues valid-email New/Qualified leads without individual selection, including unknown company types. The confirmation explains outreach overrides and live email consequences. Suppressed, processing, already queued/sent, invalid-email, missing and temporarily closed leads remain blocked. The GHL worker still checks contact identity, account, DND and duplicate/uncertain-write safeguards. Existing failed/review/held jobs can be explicitly retried and reconciled.

Bulk results show reason counts for skipped leads. Approvals retain the WordPress capability/nonce checks and encrypted server-side credentials. No credentials are returned to the browser. No Google Ads or scraper behavior changes.

## Validation

- 107 offline GHL assertions, including 503-row multi-page approval, duplicate email handling, repeated approval, override worker success, continued automatic qualification and hard safety checks.
- Desktop/mobile rendered-control browser checks, including confirmation of all-list scope and overrides.
- PHP syntax checks and diff whitespace review.
- All provider responses mocked: no live contacts or emails triggered during development.

## Operational limits

This queues handoffs; it does not send email directly. The existing worker processes one contact per cron tick (normally one minute), so a large list is not delivered instantly. WordPress cron must run; use server cron for quiet sites. A timeout while approving a very large list may leave a partial queue; repeating approval skips already queued emails. Applying the campaign tag does not prove workflow enrollment or email delivery; those remain in GHL. Valid email means syntax-valid, not mailbox verification. Explicit approval does not turn on automatic mode.

Upload plugin version 3.8.10. No companion-extension update is required for this change.

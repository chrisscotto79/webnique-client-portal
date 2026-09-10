# Direct GoHighLevel lead handoff — 3.7.7

## Setup and safe first test

1. Upload the updated plugin folder. Open **Lead Finder → GoHighLevel**.
2. Enter the private integration token into the password field and save. The token is encrypted with authenticated AES-256-GCM using the WordPress authentication salt; never commit the real token. Changing WordPress salts requires re-entering it.
3. Keep **Automatic GHL Sync OFF**. Run **Test connection & tag**. This reads tags only; it does not create contacts or send email.
4. In **All Leads**, review a test address you control, then **Approve & Send**. This is a LIVE handoff, not a sandbox: applying the tag can immediately start the existing GHL email workflow.
5. Check Recent handoffs, the contact in GHL, and actual workflow/email results. Check that existing contact tags remain intact.
6. Only after successful testing, turn automatic sync ON. Only newly inserted eligible leads are enqueued. Existing leads require approval. Turning it OFF holds pending automatic jobs for manual approval. Previously held jobs do not resume merely by turning it back ON.

Fixed destination: `NHlmSHw4intOI2FPRcnO`.
Trigger tag: **Land Clearing Cold Email** (case-insensitive check because GHL can normalize tag names; original label sent).
Scopes: `contacts.readonly`, `contacts.write`, `locations/tags.readonly`. The user's `locations/tags.write` scope is compatible but unused; this release requires an existing tag and does not create it. No workflow API permissions or n8n are needed.

## Behavior and safeguards

- Email-ready means valid email syntax and New/Qualified status, excluding temporarily closed leads. This is **not** mailbox verification, proof of business ownership, permission to email, or an audience/industry qualification check. Review imported addresses and campaign fit before enabling automation.
- Lookup by email, plus a separate phone-conflict check for recognizable US phone numbers. Reuse a matched contact without overwriting its profile, tags or DND settings; create a contact only after a confirmed empty lookup. New contact fields include company, available owner names, email, recognizable US phone, website and address. Existing GHL profiles are intentionally not overwritten by scraped data.
- Read the exact contact before tagging; require matching location/email and usable safety fields. Reject DND, active email DND, unsubscribed, bounced or deleted contacts. Never clear those flags.
- Append only the campaign trigger tag. If already present, do not apply again. “Sent” means tag confirmed, **not email delivery**.
- Persistent queue keyed by destination and normalized email prevents repeated sends across import sources. Sent/suppressed records survive deleting or reimporting leads. No UI override for local suppression in this initial release. Suppression stops future plugin handoffs, not existing GHL workflows; stop those in GHL.
- Closed, Contacted, deleted and changed-email leads do not pass the send-time check. An approved job is not silently redirected to a changed email.
- One job per minute via WordPress cron, serialized with a MySQL connection-scoped advisory lock. If advisory locking is unavailable, no job sends. Configure a server-side WordPress cron trigger on low-traffic sites. Automatic mode does not make the existing discovery/import browser jobs run unattended.
- Read failures retry after increasing delays (5 then 10 minutes), at most three attempts. Failed/held jobs require approval to retry. Contact creation and tagging are journaled **before** the request. Interrupted or uncertain writes require review; retries reconcile existing contacts/tags and do not blindly recreate contacts or reapply a possibly removed tag.
- Staff handoff actions require portal management or administrator capability and WordPress nonces. Only administrators can edit the token or automatic mode. The token is not returned in HTML or raw API errors. Fixed HTTPS API host, TLS verification and no redirects protect credential-bearing requests.

## Limitations to retain during rollout

- Requires a live test after entering the real token. Offline tests cannot prove token scopes, GHL duplicate settings, workflow configuration, deliverability or live WordPress cron/database support.
- GHL's duplicate lookup behavior depends on location duplicate settings. Existing ambiguous/duplicate contacts should be resolved in GHL. No cross-system transaction is possible: externally initiated contact creation or suppression may race a handoff. The switch cannot cancel an in-flight HTTP request or undo emails already triggered.
- An uncertain write that cannot be reconciled remains blocked for manual investigation, favoring missed/reviewable handoffs over duplicate campaign enrollment. There is deliberately no “force resend” button.
- No automatic subscription/unsubscribe webhook synchronization, delivery/reply reporting, mailbox validation service, bulk approval, or multi-location routing yet. GHL suppression is checked before each new handoff.
- Queue timestamps are UTC. The UI shows the latest 100 jobs; records remain durable. WordPress must have permission to create the queue table.
- The automatic rule currently covers every newly inserted eligible lead, not just a specific industry. Keep discovery/imports limited to the intended campaign audience or remain in manual mode.

## Related Lead Finder fixes

- Fixed adjacent HTML elements corrupting extracted email addresses; decode entities and exclude script/style contents. All crawler paths now share the extractor; bounded `/contact` fallback resolves from the website root.
- Website fetches touched by this change use WordPress safe HTTP and TLS verification. Invalid certificates/private URLs will no longer be scraped.
- Preserve valid CSV email values instead of overwriting them with scraped candidates; preserve email source for Maps/ZIP extraction.
- Failed inserts return zero rather than a stale insert ID and never enqueue a handoff. Add business-name/city duplicate checks across importer IDs; this is not a retroactive database cleanup or a replacement for global entity resolution.
- Backend jobs are not marked imported after download/insert/acknowledgement failures, allowing retry with saved leads skipped. Backend pagination and browser/transient discovery job architecture are unchanged.
- CSV exports contain only contact rows (no industry-heading/separator rows) and escape formula-like cells. Review field mapping in GHL when importing CSV manually. CSV export never applies the live campaign tag automatically.

## Verification

`php tests/lead-ghl-regression.php` covers encryption/tampering, switch behavior, approval, duplicates, contact reuse, exact tagging, local/GHL suppression, account/email conflicts, bounded retries, crash/uncertain write reconciliation, malformed responses, extraction, insert failures, UI credential omission and permission/nonce checks. All HTTP responses are mocked.

`node tests/lead-ghl-ui.cjs` renders the production controls against mocked WordPress data, checks desktop/mobile width, empty token field, default switch state and live-send confirmation. It does not submit forms to a real server.

Existing Analytics, PPC, Blog Scheduler and Service City PHP regressions plus PHP syntax validation are also run. No Ads, Analytics or blog behavior is intentionally changed.

API references: [duplicate lookup](https://marketplace.gohighlevel.com/docs/ghl/contacts/get-duplicate-contact/), [add tags](https://marketplace.gohighlevel.com/docs/ghl/contacts/add-tags/), [location tags](https://marketplace.gohighlevel.com/docs/ghl/locations/get-location-tags/), [official versioned schema](https://github.com/GoHighLevel/highlevel-api-docs/blob/main/apps/contacts.json). Uses the documented `2021-07-28` API version from the published schema.

# Lead Niche merge field — 3.8.17

One-time setup: add `locations/customFields.readonly` and `locations/customFields.write` to the private integration, then use **Set up Lead Niche field** in the GoHighLevel tab. It creates/reuses a contact TEXT field named Lead Niche, verifies destination/type and displays its returned merge key (normally `{{ contact.lead_niche }}`). No guessing of field IDs. A previous uncertain create is looked up rather than blindly repeated; if absent, resolve manually in GHL. Setup is administrator-only and nonce-protected.

Once configured, new handoffs fill an empty niche field and verify it before adding the campaign tag. Source is the first saved search keyword from source notes, with listing category as fallback. Existing values are never overwritten for another keyword. Missing/unverifiable values hold the handoff before a new tag. Without setup the previous handoff behavior remains unchanged.

**Backfill Lead Niche on existing GHL contacts** fills empty values on confirmed sent contacts only. Exact contact/account/email identity is checked. No tags, DND, queue state or email/phone fields are updated. Independent GHL contact-update automations can still react; the existing tag-added trigger is not called.

Use the returned key via GHL's email custom-value picker. Example: “I wanted to reach out about your {{ contact.lead_niche }} business.” Test grammar and a preview before use: search wording is not automatically rewritten into a service phrase. This is a niche/search label, not a verified inventory of services.

Validated with offline setup/reuse, source fallback, update-before-tag, readback and preservation tests; PHP syntax and desktop/mobile UI checks. Live field creation and contact updates require deployment and setup. No live emails sent in development.

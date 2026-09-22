# Lead Finder phone handoff — 3.12.3

New-contact creation already included normalized US phone numbers. Existing contacts matched by email did not receive missing phone numbers. Handoffs now fill and read back a missing phone before applying the campaign tag. Existing different numbers and phone/email identity conflicts are held, never overwritten. Global email deduplication remains unchanged.

US formatting and extension suffixes are normalized to the main business number. Explicit country-coded international numbers are accepted; unrecognized local formats are not guessed. This is formatting, not carrier, mobile or consent validation. Missing/invalid phones remain email-only. Email is still required; this release does not enroll phone-only leads or requeue historical sent leads.

Email, SMS and global DND restrictions block the combined campaign handoff. The plugin never clears DND or asserts consent. GHL owns email/SMS workflow actions, eligibility and delivery. No workflow changes or live texts were made. A business number collected from Google does not establish marketing SMS permission.

Uses GHL's standard contact `phone` field and existing contacts write permission: https://marketplace.gohighlevel.com/docs/ghl/contacts/update-contact/

Install plugin 3.12.3; no Chrome extension update. Verify a future authorized handoff in GHL and configure the workflow's SMS branch to check permission/opt-outs and SMS-capable numbers. Tests: `php tests/lead-ghl-regression.php` and `php tests/lead-ghl-phone.php` (mocked network).

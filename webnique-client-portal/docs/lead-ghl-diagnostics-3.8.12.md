# 3.8.12 — inspect live handoff blockers safely

The production screenshot shows repeated missing/non-Boolean DND failures and separate phone/email conflicts. These are NOT resolved by a successful location/tag connection test. The existing fixtures did not represent the live response type. Do not interpret absent or unknown DND as permission to send.

Added a staff-only, nonce-protected **Diagnose blocked contacts (read-only)** action. It reads up to three stored GHL contact IDs and reports only lead ID, DND type (and recognized Boolean-like value), email/all DND status from a fixed allowlist, tag-array presence and safety-check result. It does not print emails, phone numbers, tokens, arbitrary response values or full payloads. It never tags, creates, retries or requeues contacts. Hardened malformed channel-settings handling.

Staff should deploy 3.8.12, click the diagnostic button and share its notice. The exact live data shape is required before changing normalization. Phone/email conflicts still require identity reconciliation; no duplicate/contact safeguards were removed. This is diagnostic instrumentation, not a confirmed fix for production delivery. No extension update required.

Validation: mocked regression coverage for absent/null/string/Boolean DND, sanitized output and no writes; PHP syntax/diff checks. No live contacts or emails touched.

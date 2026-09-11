# Lead qualification and list management — 3.8.3

Outreach now requires fewer than 50 reviews and a staff-reviewed small independent
business classification. Chains/franchises and large companies are excluded.
Unknown company types and zero/missing review counts are held, never assumed to
qualify. This applies to manual approval, bulk approval and the worker's final
pre-tag check, including already queued leads. All collected listings are retained.

Company size/franchise ownership is NOT automatically verified from Maps. Staff
use Review company type, provide evidence/source, and save the classification.
The staff ID, UTC date and reason are appended to notes. Reviews do not send leads;
approve qualified leads separately. Existing records begin as unknown. Consequently
new automatic imports are held for company review rather than blindly tagged.

SEO uses the existing seven homepage deficiency checks (higher = more issues),
reusing the HTML already safely fetched during intake. No extra website request.
An administrator can enable a minimum issue count from 1–7; default 0 leaves SEO
optional because no cutoff was requested. Unassessed websites cannot pass an enabled
threshold. This is a basic HTML heuristic, not a comprehensive SEO audit; old rows
are not silently assigned assessed scores or rescanned.

Lead List filters: business name, listing ZIP, city, niche/category, status,
company type, maximum review count, minimum assessed SEO issues, email presence,
phone presence and no website. Counts and pagination use the same conditions.
Listing ZIP is the address ZIP, not the searched ZIP. Export explicitly states
that only its email selection applies; other on-screen filters do not affect CSV.

Delete all is administrator-only, nonce-protected and requires the exact words
DELETE ALL server-side, plus browser confirmation. It deletes all WordPress lead
rows, not just current results. Export a backup and pause all collectors first.
The GHL worker lock prevents deletion during a handoff. Deleted leads cannot pass
worker eligibility checks. Search history and persistent GHL suppression/handoff
records remain; remote contacts and already-running workflows are unchanged.
New imports can recreate lead rows; retained suppression history still applies.
No live leads were deleted or enrolled while developing this release.

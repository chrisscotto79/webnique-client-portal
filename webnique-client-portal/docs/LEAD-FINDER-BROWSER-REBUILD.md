# Lead Finder browser-assisted rebuild — 3.8.0

## Main workflow

**Find Leads → Lead List → GoHighLevel.** Older imports, bulk status/delete controls and server-based ZIP sweeps remain under a collapsed Advanced / legacy tools section. Existing leads and GHL credentials/settings are preserved; nothing is deleted or automatically enabled.

The main Find Leads search accepts exactly one niche keyword and one five-digit ZIP. It uses the bundled Chrome companion, not the old Florida-wide backend job or Google Places API. Searches are capped at 100 listings. Collection stops at the reported list end or bounded no-new-result/round limits, with a partial-results notice when appropriate.

Listing cards are accumulated incrementally while scrolling, then individual place pages supply address, phone, website and category. Expected Maps identity and business name are checked before combining detail evidence. Google challenges pause the collector. No automatic CAPTCHA/consent bypass or access-control evasion is implemented.

WordPress then fetches each website homepage and, if needed, `/contact`, `/contact-us`, and `/about`. The extractor checks mailto links, visible HTML text and explicit JSON-LD email properties. It preserves HTML element boundaries, decodes entities, excludes arbitrary script/style text and no-reply addresses, and prefers a matching business domain over unrelated emails. Each successful email keeps the URL where it was found. Founder names are accepted only from explicit JSON-LD Person founder data; no guessing from email addresses or reviewer/author names.

Missing websites/emails do not discard a Maps lead. Closed businesses are saved with Closed status so existing GHL eligibility excludes them. Unlike the legacy import pipelines, the new browser intake has no review-count/franchise filter. Search context is stored in notes; actual address ZIP is parsed only when present in the address. Saved results consolidate in the existing lead table, using canonical Maps identity and existing name/city duplicate safeguards. Cross-source identity resolution is conservative, not a guarantee that all historical duplicates are removed.

## Reliability and security

- Chrome session storage retains a pending listing until WordPress confirms it was saved (or already exists). Refresh in the same WordPress tab can resume without advancing past an unsaved listing.
- WordPress tab session storage retains the save receipt across an interrupted extension acknowledgement, keeping counters accurate without rescraping a saved website.
- The companion rejects other origins, other WordPress pages and subframes. It has only scripting/storage permissions and Google Maps host access; its content bridge matches the agency's admin host only.
- The extension does not fetch business websites or know the GHL token. The authenticated WordPress endpoint validates staff permissions, nonce, request size, keyword/ZIP and Maps URL.
- Website requests use WordPress safe HTTP, TLS verification, response-size bounds and short timeouts. Unknown values stay blank, rather than inferred or filled with placeholders.
- One search step at a time per browser tab; a new search cannot silently replace an unfinished one. Pause cannot cancel a request already in progress. Saved leads remain if Chrome closes; unsaved browser session progress is not durable across browser/extension restarts.
- GHL handoff logic/tag lookup is unchanged. The user deferred that issue; this release does not create a tag, change campaign routing, send test contacts, or enable automatic sync. If automatic GHL sync was already ON, its existing rule still applies to newly saved eligible leads. Keep it OFF while testing the new collector.

## Setup

Upload/zip the normal `webnique-client-portal` folder, then load its `browser-companion` folder once via Chrome's **Load unpacked** extension installation. See `browser-companion/README.md`. No command-line installation, backend server or copy/paste extraction is needed during normal searches.

## Verification

- `php tests/lead-browser-regression.php`: 31 intake/extraction/security checks with mocked WordPress/HTTP data.
- `node tests/lead-browser-ui.cjs`: 22 checks covering the actual collector on offline Maps DOM, Chrome worker state with mocked extension APIs, origin/frame restrictions, duplicate listings, persistent pending state, acknowledgement guards, desktop/mobile UI and interrupted-save recovery. These are not a packaged-extension installation test in the user's Chrome profile.
- Live read-only smoke test: `Plumbers in 32825` returned 7 initially visible listing cards; one detail page returned a matching business name, website, phone and address. No WordPress import or GHL outreach occurred during this test. This is not an exhaustive ZIP crawl or an end-to-end deployment test.
- Existing regressions, syntax checks, diff review and credential-boundary review run before delivery.

Remaining limits: manual Chrome installation and a live WordPress end-to-end test are required; Google markup/access can change; public emails may be absent, unrelated, stale or undeliverable; founder data is often absent; surrounding ZIPs may appear; current Chrome session must remain open; matching across old importer IDs is imperfect; automatic discovery while the computer is off is not included.

Implementation references: [Chrome message passing](https://developer.chrome.com/docs/extensions/develop/concepts/messaging), [scripting API](https://developer.chrome.com/docs/extensions/reference/api/scripting), [session storage](https://developer.chrome.com/docs/extensions/reference/api/storage). No code or credentials from the user's downloaded extension were modified.

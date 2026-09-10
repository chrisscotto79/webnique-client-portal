# Golden Web Marketing Chrome companion

This companion works with the **WordPress plugin**. It replaces the old console-script/clipboard scraping flow. It does not use Google Places API, a paid scraping API, n8n or a Node server.

## Install once in Chrome

1. Keep this `browser-companion` folder on your computer (it is inside `webnique-client-portal`).
2. Open `chrome://extensions`.
3. Enable **Developer mode**.
4. Click **Load unpacked**, and select this folder containing `manifest.json`.
5. Open or refresh **Golden Web Marketing Portal → Lead Finder** in Chrome.
6. The Find Leads page should say **Chrome companion connected**.

There is no extension popup or token to paste. All collection controls live in WordPress. Loading the companion is a separate one-time Chrome step; uploading a WordPress plugin cannot install a Chrome extension automatically. When companion files change in a future update, click **Reload** on its Chrome extensions card, then refresh WordPress.

### Connection fix: plugin 3.8.1 / companion 1.0.1

The original WordPress script sent a single HELLO before Chrome's document-idle listener could load. The listener now loads at document-start, and WordPress retries only the read-only HELLO until connected or the 20-second deadline. The new **Reconnect companion** button retries without starting collection. Search-status failures no longer report the extension as missing. An invalidated extension context asks for a WordPress refresh.

After updating these local files, click **Reload** on this extension's card, upload plugin 3.8.1 to WordPress, and refresh Lead Finder. The update does not expand host access or permissions. `node tests/lead-handshake.cjs` tests delayed listener startup, timer cleanup, reconnect, search-status failure isolation and invalidated contexts with the actual bridge script and mocked Chrome runtime. Existing browser-intake, collection UI and GHL regressions also pass. The live user installation must still be verified after reload.

## Use

Enter a keyword such as `Plumbers` and ZIP `32825`, then click **Find leads**. Keep Chrome and the WordPress tab open, with the computer awake. The companion opens its own Maps search tab, scrolls and collects up to 100 listing URLs, then uses a second owned tab for individual listing details. Do not navigate these Maps tabs elsewhere during collection.

WordPress fetches the business website HTML and checks for public emails. It saves one business at a time to **Lead List**. No website or email is required to retain a business for calling. Phone numbers, URLs, email source, rating, review count, address, category and explicit founder-name evidence are retained when available.

Use **Pause** to stop after the in-flight request and **Resume / retry** to continue. A WordPress page refresh in the same Chrome tab can resume the pending search during the same browser session. Closing Chrome/reloading the extension ends session storage; already-saved WordPress leads remain. A different WordPress tab has a separate browser search. Do not run simultaneous searches in multiple WordPress tabs for the same list.

If Google shows a consent or verification prompt, inspect the Maps tab yourself, then resume. The companion does not bypass challenges. If a page fails to load, pause and inspect it; detail fields can remain missing and are marked incomplete after the bounded wait.

## Scope and privacy

- Runs only on `https://goldenwebmarketing.com/wp-admin/admin.php?page=wnq-lead-finder` (also `www`) and its own `https://www.google.com/maps/` tabs.
- No GHL tokens, WordPress passwords, cookies API, arbitrary website access, or paid APIs in the extension.
- Search state is held in Chrome session storage; the WordPress page holds a temporary save receipt in tab session storage until acknowledged. Public lead data is therefore briefly present locally as well as in the WordPress lead table. No third-party collector receives the list.
- WordPress authorization and nonce checks protect every saved lead. Website URLs use safe HTTP requests with TLS verification and bounded redirects/responses/timeouts.
- Existing GHL approval/automatic settings still control outreach. This release does not fix or change the campaign-tag lookup. A missing tag does not prevent scraping or saving leads.

## Limits

Maps may return nearby businesses rather than only addresses within the entered ZIP. Search ZIP is recorded as search context, never falsely assigned to a business address. Results are not guaranteed exhaustive: visible-list limits, Google DOM changes, prompts and the 100-listing cap can limit coverage. This implementation uses English Maps labels (`hl=en`). Website HTML lookup cannot see all JavaScript-rendered or obfuscated emails. First names are not inferred from an email address: only an explicit structured-data founder name is used in this version. A founder is not necessarily the current owner. Website emails are not deliverability-verified.

The code is a new implementation using the same browser-DOM approach as the supplied extension, not a copy of its files.

# Facebook client campaigns — portal 3.9.0 / companion 1.1.0

## Updating

Stop the existing Facebook schedule before installing. Update the WordPress plugin and reload the separate `facebook-companion` extension in Chrome, then refresh every open Facebook Groups admin tab. Lead Finder's Maps extension is unchanged. Check connection, select a company, save its plan, and Test before starting a week. Test submits a real post when used against Facebook; automated development tests never do.

## Client source and preservation

The selector uses `WNQ\Models\Client::getAll()`, the existing Money Management client table also referenced by Analytics. No client records, users, accounts or client schema columns are added. Golden Web Marketing is a built-in campaign scope, not a new client record. Its existing plan, weekly job keys and settings remain in their original WordPress options. Other clients use their existing numeric primary key as a namespace; renaming a company does not change its campaign ownership.

Each client has its own groups, primary message, up to 20 additional message variants, optional appended link, up to four Media Library images (JPEG/PNG/WebP; 4 MB combined), schedule/timezone/cutoff/repeat settings, daily attempt limit (1–50), progress and weekly records. Variants rotate by group-list index. The daily limit determines batch size: a limit of 10 fits 70 weekly links; 50 fits 350. Attempts include tests and uncertain submissions, not only confirmed posts, to avoid overshooting the limit. There is no automatic image creation or fetching from external image URLs.

## Cross-client controls

- All requests carry a fixed client scope from the loaded page. Invalid/deleted IDs fail closed.
- A shared request mutex serializes changes, and a persistent campaign owner prevents another company from starting while the first company's schedule is enabled. Stop the owner before switching. Its outstanding dispatch/cooldown must expire before a different client starts (normally six minutes after the last result; up to eight minutes after an interrupted dispatch).
- Content cannot be saved over an enabled or still-in-flight campaign. Switching is disabled during a local run. Unsaved form changes must be saved before Start/Test.
- Every job includes the client ID and name. Browser dispatch validates both against the requesting page. Results/review keys must belong to that client's namespace. Older companions cannot publish from the new controls.
- The extension shows a campaign banner in its background Facebook tab. This is a campaign label, **not Facebook authentication**. It still uses the Facebook profile/Page already signed in to Chrome. Selecting a client does not sign in as that client or switch the Facebook posting identity; the operator must verify the intended identity.
- Existing drafts and attachments are not overwritten. Missing or unconfirmed image-upload controls skip that group without clicking Post. Facebook UI variations can still require review.
- The original global per-group rolling 24-hour duplicate guard remains shared across clients intentionally: changing clients cannot bypass it. The six-minute browser dispatch interval is also global. Account/login restrictions stop; group-specific failures skip; ambiguous post-click outcomes remain held.

## History

Use History week to review a client's saved results. New weekly URL snapshots retain access to results even after group lists change. Old option records remain untouched; legacy historical URLs removed before this version cannot be reconstructed if they were never stored. Their raw records are not deleted. Existing confirmation provenance remains distinct: Facebook-confirmed, manually marked, or legacy/unverified. Viewing history does not change dispatch scheduling; Start/Resume returns progress to the current week.

## Verification

- `php tests/facebook-clients.php`: original queue regressions plus client ownership, wrong-client results, concurrent controls, legacy preservation, history, limits and save validation.
- `php tests/facebook-group-plan.php`
- `node tests/facebook-companion.cjs`: mocked posting, attachment confirmation, client mismatch and client banner; original duplicate/restart/draft/login checks.
- `node tests/facebook-controls.cjs`, `node tests/facebook-bridge.cjs`
- `php tests/analytics-regression.php`, `php tests/analytics-ads-cost.php`

No live Facebook posting, group joins, production WordPress migration, client edits or credential access occurred during these checks. Money Management and Analytics implementation files are unchanged.

# Facebook publisher 3.8.19

Adds explicit sign-in, first-group publish, today's batch, daily page-hosted scheduling,
and Pause controls to the saved weekly planner. Includes a separate, origin-restricted
Facebook Chrome companion; leaves Maps companion permissions and files untouched.

All Facebook credentials remain in Chrome. Capability and nonce checks protect the
WordPress queue. Atomic group/week reservations prevent concurrent WordPress pages
from claiming the same normalized URL; extension storage also prevents replay after
a worker restart. Uncertain outcomes remain reserved, not automatically retried.
No long-lived credential, cookie, or Facebook source dump is sent to WordPress.

The first-group action is an explicit real-post test; no action runs on plugin upload
or page load. Scheduled runs require the WordPress tab and Chrome to remain open.
Time is evaluated server-side in the configured timezone. Missed past days are not
sent in a catch-up burst. Non-repeating plans stop after the first active ISO week.

Limits: English composer labels; no attachments; URL aliases not reconciled; no
automatic recovery from ambiguous submissions; no live Facebook posting QA yet.
The user must verify the logged-in profile and test in a group permitting the post.
Do not claim universal Facebook compatibility or a safe daily posting threshold.

Tests:
- `php tests/facebook-group-plan.php`
- `php tests/facebook-publish.php`
- `node tests/facebook-companion.cjs`
- PHP and JavaScript syntax checks

All posting tests are mocked. No real Facebook posts were made.

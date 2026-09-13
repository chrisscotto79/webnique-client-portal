# Facebook Publisher companion 1.0.2

Version 1.0.2 requires WordPress plugin 3.8.20 and expires a publishing authorization
after two minutes. The server adds an independent rolling 24-hour group-ID guard
(with three minutes of dispatch padding). Numeric group-ID links are required to
avoid alias duplicates. The weekly guard remains in force. Tests, scheduled jobs,
and unknown outcomes all share the limit. A proven pre-click failure releases its
own reservation. This cannot track posts made manually or by unrelated software.

Version 1.0.1 waits for the Post button while link previews load, reacquires the
composer after Facebook rerenders it, recognizes aria-labelled Post controls, and
tolerates whitespace-only rich-text changes. Non-whitespace message differences
still block posting. Reload this extension in Chrome and refresh WordPress after
updating; WordPress plugin files do not need to change for this patch.

This is a separate Chrome extension, not an update to the Google Maps companion.

1. Install WordPress plugin version 3.8.19.
2. Open Chrome's extensions page, enable Developer mode, choose **Load unpacked**,
   and select this `facebook-companion` directory (the directory containing manifest.json).
3. Refresh the WordPress **Facebook Groups** page and click **Check connection**.
4. Save the group links and your actual message. Unsaved form edits are not published.
5. Click **Open Facebook / sign in**. Sign in directly on Facebook and verify the
   active profile. Do not enter your Facebook password anywhere in WordPress.
6. Return to WordPress. **Publish to first saved group** sends a real post after
   confirmation, regardless of today's weekday. Choose an appropriate group first.
7. **Publish today’s batch now** uses today's Monday–Sunday allocation immediately.
   **Start daily schedule** waits until the saved time and processes today's batch,
   then keeps checking while the page is open. Each completed request is followed
   by a 60-second interval. This is not a guarantee against account restrictions.

Keep Chrome, the WordPress page, and the computer awake. Closing/reloading WordPress
stops the page runner; use Start again. This is not a cloud scheduler. The companion
owns at most one reusable Facebook tab; it does not close your other tabs. It uses
the normal Chrome Facebook session, not exported cookies or an API token.

English Facebook controls are currently supported. A login/security prompt, a
changed layout, or an uncertain submission pauses publishing. Group rules and
moderator approval still apply. Do not use this to bypass posting restrictions.

The server reserves each normalized group URL per ISO week before the browser runs.
Confirmed and uncertain submissions cannot be automatically repeated that week.
Minimal internal status is retained, with no user-facing posting history or cookies.
Numeric and named links for the same group are not automatically reconciled; use
one URL per group. An uncertain job needs manual investigation rather than a reset
and blind retry. A first-group test counts against that group's weekly submission.

Offline tests use simulated Facebook pages. A real-account posting test has NOT been
performed. A closed composer alone never counts as success; the current implementation
requires a new, recognized Facebook confirmation notice. Other layouts stop for review.

# Three-tab listing pool and continuous recovery — 3.8.9 / companion 1.0.6

The companion loads up to three listing-detail pages concurrently within the
current ZIP. Search collection uses one tab; that same tab becomes slot zero.
Two additional slots are created only when enough listings exist. Slots are
reused as the current listing is saved/acknowledged. No simultaneous ZIPs or
parallel WordPress website crawls/GHL sends are introduced. All pool tabs close
at ZIP completion or search replacement. User-navigated unrelated tabs are kept.
Missing owned tabs are replaced; slot IDs and assignments persist in session
storage. Slot count is capped at three, not three new tabs per listing/ZIP.

Read-ahead only overlaps browser loading. WordPress saves and ACKs stay sequential,
and each result must match the current listing ID and name before merging evidence.
Lost ACK reconciliation and exactly scoped client/account mapping remain unchanged.

Recoverable Chrome errors, slow loading and incomplete listing transitions now
retry automatically instead of all being hidden behind a generic terminal pause.
Cooldown grows from 5 seconds to a maximum of 60 seconds and continues while the
run is active. Pause cancels recovery; in-flight writes finish safely. Retry logs
include the specific reason. No uncertain START requests are blindly replayed.

Google verification/consent, navigated-away tabs, expired WordPress sessions,
invalid job identity and invalid acknowledgement remain protected stops. The
extension does not solve CAPTCHAs, click consent, or invent/skip missing evidence.
Leaving Chrome running with an awake computer and the portal tab open is still
required. This is not an always-on remote worker. Memory usage can still be higher
than one tab; three is a cap, not a crash-proof guarantee or measured speed multiplier.

Install BOTH plugin 3.8.9 and companion 1.0.6. Pause first, reload the extension,
then refresh the same WordPress tab once and Resume. Existing single-tab jobs can
be adopted into the pool. No live searches, lead deletions or campaign enrollments
were performed during development.

QA: offline simulation of 100 ZIPs / 1000 listings with peak three Maps tabs,
exact result/ACK checks, cleanup after each ZIP and worker restart mid-pool;
continuous retry past six attempts and Pause cancellation; existing intake,
handshake and GHL regression suites; PHP/JS syntax and diff checks.
Actual Google rendering, network speed and host memory still require a live smoke test.

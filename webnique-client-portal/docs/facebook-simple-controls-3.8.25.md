# Simplified Facebook scheduler, 3.8.25 / companion 1.0.7

Five main controls: Start, Test, Stop, Resume, Check connection. Setup/sign-in and
safety text are collapsed. The old immediate-batch button is removed. Saving a plan
stops scheduling; Start explicitly arms/rearms the one-time week without deleting
daily/weekly group records. Resume never resets those records or the schedule week.

WordPress stores enabled state; next-job requests honor Stop. The extension can
receive cancellation while busy, marks the active token cancelled in its owned
Facebook tab, and checks before Join/Post. An already-dispatched click cannot be
recalled, and browser disconnection can prevent immediate cancellation.

A shared atomic dispatch lease enforces spacing across clients. It initially lasts
480 seconds (120-second authorization + 360-second cooldown). A received result
sets the lease to six minutes from completion. Empty polling does not consume it;
waiting for it releases the candidate group's unused daily reservation. The daily
and weekly duplicate guards are unchanged. Polling occurs every ten seconds, so
dispatch may be later than the exact interval, particularly in throttled tabs.

Test never reports success just because a job was queued or a composer was closed.
It requires the existing recognized Facebook confirmation. Pending approval is
reported separately. Unknown clicks remain held for review but group-local unknown
outcomes no longer stop subsequent groups. Transport/login/security errors stop the
schedule. Per-group current-week status and messages appear in weekly dropdowns.

Verification: 41 PHP queue checks; mocked companion tests; rendered production UI
tests covering control layout, separate errors, per-group status, advancement after
unknown outcomes, Stop and Resume. No live Facebook publication was performed.

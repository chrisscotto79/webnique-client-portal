# Manual publishing independent of one-time schedule expiry

The global first-week marker previously blocked all next-job requests, including
the explicitly confirmed first-group test. On a later ISO week the test returned
“One-time week finished” before reaching any group or browser operation.

Version 3.8.24 restricts that scheduling condition to scheduled mode. Manual first
group and today's-batch actions continue through the normal per-group eligibility
checks. Manual attempts no longer start the one-time scheduling clock.

Daily reservations, weekly duplicate protection, unknown-result holds, cutoff,
confirmation prompts and Chrome dispatch expiry remain unchanged. No historical
reservations are deleted. Companion 1.0.6 does not need to be reloaded for this
WordPress-only patch. Refresh WordPress after updating the plugin.

Regression tests reproduce an expired week, ensure a new eligible group's manual
test receives a job, and verify repeats and daily-guard bypasses remain blocked.
No live Facebook requests were made.

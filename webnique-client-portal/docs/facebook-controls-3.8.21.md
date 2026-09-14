# Facebook publishing controls 3.8.21 / companion 1.0.4

- Skip weekly handled/held jobs, nonnumeric group links, and daily-guard exclusions.
- Explicit group-unavailable/restricted notices return a group-local pre-click
  result; the server records a weekly skip and continues. Generic layout issues,
  security/login prompts, and uncertain clicks pause the entire run conservatively.
- Progress returns today's (or the first-group test's) total, submitted, awaiting
  approval, skipped and review counts. Updated after requests and on page load.
- Review shows current-plan/current-week uncertain or expired reserved jobs only.
  Active unexpired reservations cannot be resolved. The user must check Facebook,
  pause the runner, and confirm Posted or Definitely not posted — skip. Both retain
  daily protection; neither resends a post. Skipped remains skipped for that week.
- Same-day cutoff is validated on save and checked before queue claims. Per-job
  expiration is capped at cutoff and checked immediately before clicking Post.
  Default cutoff is 18:00. Missed days are not backfilled. Overnight windows are
  intentionally unsupported.

Verification: planner tests, 24 PHP publishing queue checks, mocked browser tests
including group-local restriction and account/login classification, syntax checks.
No live Facebook posts were made. These checks do not prove all Facebook layouts
are supported.

# Monthly SEO management — Portal 3.12.0

## Workflow

The existing SEO Portal menu and client cards remain. Manage SEO now opens a monthly workspace containing SEMrush, Google Search Console, Technical SEO, On-Page SEO, Content, Internal Linking, Backlinks, Local SEO / Google Business Profile, Conversion Tracking and Monthly Reporting.

Twenty-four default recurring tasks cover the ten categories. Each client can edit its reusable plan: task title, category, due day, priority and enabled state, or add new recurring tasks. Plan changes affect newly generated cycles; they do not rewrite existing months. Due days beyond the end of a month use the final calendar day.

Each monthly task has Pending, In Progress, Completed or Not Applicable state; due date; completion date; priority; and notes/evidence. Overdue is derived from the due date of an unfinished task. Marking Completed defaults its completion date to today; reopening clears it. Work can be completed late without moving it out of its original reporting cycle.

## Operational dashboard

- Today plus overdue, current calendar week, overdue count and selected-cycle completion percentage.
- Work queue filtered by time window and client, with twelve tasks per page.
- Client cards retain Manage SEO, monthly completed/applicable counts, completion progress, overdue work, last manual activity and a directly linked recommended task.
- Recommendations sort earliest due date, then high/medium/low priority, then in-progress work and stable task ID.
- Open tasks from earlier monthly cycles remain actionable. Monthly completion only counts the selected cycle; Not Applicable tasks are excluded from the denominator. An empty/all-N/A cycle displays a dash rather than misleading 0% or 100%.

## Results and reports

SEMrush task tracking covers Site Audit, Position Tracking, Organic Research, Keyword Gap, Backlink Audit, Backlink Gap and competitor research. The SEMrush section displays site health, ranking gains/losses and referring domains. Monthly results store these metrics plus keywords tracked, GSC clicks/impressions/CTR/average position, organic sessions and leads, content published, pages optimized, backlinks acquired, citations built, GBP updates and technical fixes.

Narrative fields capture wins, rankings, traffic context, competitors, opportunities, content URLs, backlinks/citations, technical evidence, lead attribution and next-month priorities. The monthly summary automatically includes recorded results, prior-month deltas, narratives and completed tasks with notes/dates. A print view supports browser Save as PDF. The history table opens each preserved cycle for review and comparison.

Metrics are manually recorded from SEMrush, GSC, GA4 and other reports; this release does not add automatic API ingestion. Blank means not recorded, distinct from an explicit zero. Position improvements mean a lower average position. CTR deltas are percentage points. Counts of published content or fixes are entered outcomes and are not inferred from checking a task.

## Rollover and history

New tables `wnq_seo_cycles` and `wnq_seo_work` preserve monthly snapshots and avoid modifying legacy checklist/report rows. Each cycle snapshots its enabled plan; a unique client/month/template key prevents duplicate tasks. Partial generation is retryable and never overwrites task progress. Generation does not count as SEO work activity.

An hourly WordPress cron event creates current cycles for active shared clients on Website + SEO or Website + SEO + PPC tiers and catches up gaps since their first cycle. Opening SEO Portal also performs catch-up, including on the first use after upgrade. As with all WP-Cron work, timing depends on site traffic or a configured system cron. All dates use the displayed WordPress site timezone. Inactive clients retain history but do not receive new automatic cycles.

Existing launch, one-time checklists and old monthly tasks/reports remain under Legacy setup & history. They are not automatically copied into the new completion totals or metrics. The prior automatic checklist replacement routine is disabled, so an upgrade no longer replaces historical monthly rows. Legacy navigation and edits stay in the legacy view.

## Storage and safety

Task and metric/report saves require staff permission, nonce validation, a valid client and server-side field validation. Task identity is scoped to the client. Optimistic revisions reject stale task/report saves rather than silently overwriting newer work. Recurring-plan settings use the existing WordPress options store and last-save-wins semantics. No external publishing, outreach, key generation or billing changes occur.

## Validation and installation

Validated with 44 SQLite-backed persistence/rollover/history/permission tests, PHP syntax checks, and offline Chromium desktop/mobile UI tests for filtering, pagination, task links, categories, results and report/history rendering. SEO directory, shared-client and Blog Scheduler regressions also pass. Database creation uses WordPress dbDelta; incomplete schema upgrades remain retryable. SQLite tests exercise model queries via a WordPress database adapter; they do not substitute for a live WordPress/MySQL installation check.

Install the `webnique-client-portal-3.12.0.zip` update in WordPress, then open SEO Portal. No extension update is required. This release does not install itself on the live site or generate production monthly records until it runs in WordPress.

## 3.12.1 eligibility correction

SEO Portal cards, totals, queues, monthly and legacy client views now require an active shared client with tier `website-seo` or `website-seo-ppc`. Website-only, PPC-only, inactive and Analytics-only identities are excluded. Monthly generation uses the same rule. Historical records are retained. SEO OS agent/key connectivity retains its separate directory and is unchanged. Eligibility is checked again when saving monthly work.

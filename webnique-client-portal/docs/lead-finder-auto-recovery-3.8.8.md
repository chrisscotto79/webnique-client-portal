# Lead Finder QA and automatic recovery — 3.8.8 / companion 1.0.5

## Findings and changes

Every missing STEP/ACK reply previously ended the loop and required manual Resume,
even if the worker had already stored the result. Temporary message-channel errors
and busy responses were indistinguishable from terminal errors.

The running page now retries temporary companion interruptions with 5/10/20/30s
backoff (up to six recovery attempts without a confirmed save). Each retry first
reads STATUS and checks the exact run ID. A busy worker is waited on, not replaced.
Lost ACK replies reconcile the stored job and saved receipt rather than re-saving
or blindly repeating the acknowledgement. No additional Maps tab is created.
Pause cancels pending recovery and guards continuations after awaited operations.

Terminal errors (Google prompts, mismatched jobs, an invalidated extension context,
WordPress errors, missing jobs) remain attention states. Uncertain START requests
are not automatically repeated. No automatic page refresh or credential forwarding.
Successive progress resets the temporary-failure counter. Retries stop after six
failed recoveries rather than hammering Chrome indefinitely.

## QA

Offline browser/worker tests: 100 sequential ZIPs bounded to one Maps tab, worker
restart with pending result, stalled injection, exact acknowledgements, lost STEP
reply auto-recovery, lost already-applied ACK without another save, Pause cancellation,
sustained outage retry exhaustion, responsive UI and handshake recovery. PHP intake
and GHL regressions, PHP/JS syntax and diff review. No live lead collection or GHL
enrollment performed; a live Chrome test is still necessary after deployment.

## Operating limits

Upload plugin 3.8.8 and reload companion 1.0.5, then refresh WordPress once.
Keep Chrome and the original WordPress page open and the computer awake. This is
not a server-hosted crawler: it cannot work through computer sleep, Chrome exit,
extension removal/reload, an expired WordPress login, or Google verification prompts.
Browser background throttling may slow it. Those conditions are not bypassed.
The current tab stores the pending bulk plan; saved leads/history remain in WordPress.

No claim that every reported live timeout's underlying cause is established. The
verified defect is that recoverable missing replies always required manual action;
the new recovery path addresses that without relaxing data or tab-count safeguards.

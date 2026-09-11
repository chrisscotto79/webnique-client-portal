# Companion response recovery — 3.8.6 / extension 1.0.4

Screenshots showed a successful initial handshake followed by a request timeout.
They do not establish the exact live browser cause. Code inspection found an
unbounded executeScript wait that could hold the worker lock indefinitely, an
unavailable STATUS while busy, and timeout copy telling users to reload everything.

Maps reads now request immediate injection and return not-ready after an 8-second
wait. Storage writes, acknowledgements and tab creation are NOT blindly retried
or raced. STATUS can report a busy worker; Resume checks this before proceeding.
Non-handshake response timeout is 45 seconds with the failed command identified.
Connection status no longer stays confidently connected after a timeout.
Empty message-channel replies now produce an actionable error rather than silence.

One owned Maps tab and receipt-before-ACK behavior remain. Google prompts, closed
tabs, and unavailable WordPress endpoints may still require operator action.
Reloading an extension invalidates the old page bridge: after installing 1.0.4,
refresh WordPress once. During normal retries use Resume first rather than
repeatedly reloading the extension. Existing bulk state remains in tab session
storage; closing the WordPress tab may lose its pending queue.

Offline tests simulate stalled injection, check STATUS while busy, verify timeout
releases the lock and confirm subsequent STEP recovery. The 100-ZIP one-tab stress
test and handshake/invalidated-context tests still pass. No live Google collection
or GHL email enrollment was performed. A live-browser smoke test remains required.

Chrome injection reference:
https://developer.chrome.com/docs/extensions/reference/api/scripting

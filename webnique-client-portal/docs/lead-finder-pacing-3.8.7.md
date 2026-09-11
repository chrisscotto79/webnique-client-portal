# Lead Finder pacing — 3.8.7

Collection remains sequential with the companion's one-owned-Maps-tab limit.
No new tabs, parallel website crawls, background workers, or GHL writes were added.

After a confirmed save/ACK, the next listing starts after 200ms rather than 1.8s.
The same shorter delay applies when search collection finishes and detail review
starts. Maps load polling/scrolls retain the previous 1.8s pacing and retry limits;
slow-page timeouts and collection limits are not reduced to hide missing data.

Browser intake now tells the email extractor that it already attempted the homepage.
A failed or empty homepage is no longer requested a second time immediately.
The three contact/about fallbacks are still attempted; default extractor behavior
for other callers is unchanged. A transient failed homepage can be revisited
manually later, but is no longer retried immediately in the same intake operation.

The UI identifies website checking/saving and logs its elapsed time. This includes
WordPress processing and acknowledgement time, not only website fetch time.

Savings are workload-dependent: 1.6 seconds of fixed delay per applicable transition,
plus any avoided duplicate homepage request. No live throughput or memory benchmark
is claimed. Slow external websites, Maps loading, Chrome background throttling,
and network/hosting performance can still dominate runtime.

Validation: offline browser pacing assertion (200ms after ACK, 1800ms waiting on
Maps), existing 100-ZIP one-tab stress test, stalled-worker/handshake recovery,
intake tests proving one failed-homepage request, GHL regression checks, PHP/JS
syntax and diff checks. No real contacts sent or searches started.

Upload plugin 3.8.7. Companion stays 1.0.4; no extension reload is needed if that
version is already installed. Pause the active run before replacing plugin files,
refresh the same WordPress tab and use Resume to retain the session queue.

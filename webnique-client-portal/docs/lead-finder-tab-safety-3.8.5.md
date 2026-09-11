# Bulk search tab safety — plugin 3.8.5 / companion 1.0.3

Root cause: every ZIP opened a search tab and a separate reusable detail tab,
but neither was closed at completion. Sequential ZIP processing therefore still
accumulated up to two heavy Maps tabs per ZIP.

The companion now reuses the search tab for listing details and closes owned
Maps tabs after persisting the final acknowledgement, including empty-result
runs. A replacement search cleans up the previous tracked tabs first. At most
one batch may own collection across WordPress tabs in this Chrome profile.
Pauses/prompts keep the single Maps tab available for operator attention.
Cleanup failures stop advancement rather than allowing unbounded new tabs.
Tabs navigated away from Maps are preserved, never forcibly closed/reused.

Install BOTH updates: upload plugin 3.8.5, reload the unpacked companion in
chrome://extensions (version 1.0.3), and refresh WordPress. Old companion versions
are blocked before starting or resuming collection. Close accumulated old tabs
manually first: older ZIP tab IDs were overwritten and cannot safely be recovered
without risking unrelated user tabs. Do not restore all crashed Maps tabs.

Tests simulate 100 sequential ZIPs and assert a peak of one owned Maps tab and
zero at each completion. Also cover interrupted replacement, second-portal
blocking, acknowledgement safety and worker restart. Browser UI tests are offline;
this is not a live Maps memory/CPU benchmark or a guarantee against every possible
computer crash. Google page size and unrelated Chrome tabs still affect memory.

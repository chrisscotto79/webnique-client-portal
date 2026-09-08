# Event reconciliation — 3.7.4

The lower Tracked Actions cards previously displayed eventCount alone, while Form Leads and Email Leads used keyEvents. One email click and zero email key events can therefore legitimately appear for the same event and dates. The production screenshot alone cannot establish whether the difference was due to GA4 setup, counting method or configured event names.

The lead summary now displays total events, key events and exact configured names for both categories using the same GA4 response that supplies its totals. Differences have an explicit explanation. Tracked Actions requests and displays both metrics and each exact event name. Existing event counts are preserved; clicks are not silently reclassified as key events. The activity cache version changes so new evidence appears immediately after updating.

Validation: one email event with zero key events, existing lead calculations and provider independence, PHP syntax, controller rendering, and browser evidence rendering. Live GA4 property access is still needed to establish why a particular event has no key-event count.

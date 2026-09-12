# 3.8.14 — hands-free search and handoff

Authorized one-time upgrade enables Automatic GHL Sync when credentials are saved. A later OFF choice is preserved. Fresh valid-email New/Qualified leads enqueue without company review, review-count or SEO thresholds (`auto_fast` mode). Existing suppression, identity checks, DND policy, uncertain-write reconciliation and the permanent email-key ledger remain. Missing email stays saved for calling.

Starting a bulk search also starts a bounded, resumable scan of all existing leads, queuing valid emails with no previous handoff. Previous failed/review/held jobs retain their safety holds; this does not blindly replay them. The Find page transfers jobs sequentially alongside Maps collection, with live tagged/queued/held status and total new leads/new leads with email across completed and current ZIPs. Bulk counters persist in the existing session queue; scan cursor persists in the same browser tab. Response loss may underreport scan acknowledgement counts but cannot duplicate queue entries.

Deduplication uses the existing unique SHA-256 key of destination plus lowercase/trimmed email, independent of keyword or ZIP. Sent/suppressed ledger rows survive lead deletion. An existing campaign tag is not applied again. Uncertain tag outcomes remain held, never blindly retriggered. This guarantee depends on retaining the handoff database; restoring an old database or manually removing ledger rows loses history. Different aliases are different email strings.

Keep Chrome awake and the Find page open. Network/authentication failures and Google verification challenges may still require attention. Cron remains fallback. GHL controls email delivery. Existing list scanning does not automatically retry unresolved phone/email conflicts. No extension change or private-token change needed.

Validation: mocked GHL tests cover unknown companies, automatic enqueue/worker, normalized cross-keyword/ZIP dedupe, retained ledger after deletion, emergency OFF, backlog scan replay and invalid emails. PHP/JS syntax and existing UI regressions checked. No production emails sent in tests.

# 3.8.18 — recover unexpected HTML responses

Search save/history requests now share guarded response handling. HTTP 429/5xx, network failures, 90-second timeout, malformed JSON and missing response data use the existing cooldown/reconciliation path. HTTP 401/403, login redirects/forms and WordPress -1/0 sentinel responses pause with actionable messages. Raw response HTML is never printed. A non-JSON response alone does not identify the upstream cause (host/proxy/security/login); server logs may be needed if persistent.

No pending listing is acknowledged on parse failure. Existing place-ID intake dedupe, save receipt and ACK flow remain unchanged. History begin retries before starting the next ZIP are handled separately from an active collector job. Bulk queue, tab cap, email ledger and GHL behavior unchanged. A save committed before response loss may be reported as duplicate on retry; it is not lost or created twice.

Validation: 13 response tests covering HTML, HTTP failures, sentinels, network failure and valid JSON; existing collector/browser regressions; JS/PHP syntax and diff checks. No live leads/emails triggered. Plugin update only, no extension reload required. Keep the existing WordPress tab to retain its bulk session queue, refresh after plugin upload and Resume/retry.

# Blog Scheduler review — 3.7.6

Reviewed queue CRUD, manual publishing/generation handlers, worker claiming/recovery, client-agent selection, publishing response handling, and queue controls.

Fixed:
- An explicitly assigned inactive/missing agent no longer falls back to a different client site.
- Publishing requires a successful response with a positive post ID; login HTML, malformed JSON and explicit errors cannot mark a queue entry published. HTTP redirects are disabled on credential-bearing publishing requests.
- The actual permalink returned by the agent takes priority over the locally guessed blog URL.
- Image-save and single-delete handlers verify the selected client owns the queued post. Bulk deletion requires a client.
- Edit/image-save/delete requests reject jobs already generating or publishing. All queue deletion queries exclude processing jobs, including bulk operations.
- Failed drafts expose Generate/Regenerate Content, and preview errors direct staff to generation instead of publication.
- Failed inserts cannot reuse an old insert ID. Add/import/batch counts reflect successful database inserts; adding a single post reports save failure.
- WordPress request slashes are removed from added/edited titles.

Validation uses the actual publisher/model/handler methods with offline HTTP/database fixtures: malformed and explicit-error responses, successful publication response, redirect handling, permalink choice, exact site selection, failed inserts, deletion guards and client ownership. PHP syntax and diff checks also pass.

No live publishing or AI generation was performed. Live agent idempotency, WordPress cron execution and database concurrency require staging verification. Handler-level edit guards do not constitute a transaction spanning the entire external publishing process. Existing cron schedules and content-generation policy were not changed.

# Facebook Groups — initial draft planner

This increment adds an administrator-only Facebook Groups menu under the portal.
Paste up to 350 direct group URLs, one per line, or Markdown links. Saving validates
the complete list before replacing the previous draft, canonicalizes supported
Facebook hosts, strips tracking parameters, and removes exact normalized duplicates.
Numeric-ID and named aliases cannot be reconciled without inspecting Facebook.

The first 50 unique groups belong to Monday, the next 50 to Tuesday, and so on.
The page saves message text, timezone, daily start time, and an optional weekly
repeat preference. Empty drafts are allowed. Preferences do not activate posting.

No extension permissions were changed, no credentials are collected, and there is
no scheduler runner or Facebook connection in this increment. The UI explicitly
labels this as draft-only. No public posting or live scheduling has been tested.

Next implementation: a separately scoped browser companion; authenticated queue
claims; one reusable group tab; minimal durable weekly submission state; visible
pause on login, challenges, or uncertain submission; and confirmation of submitted
versus awaiting group approval. Do not blindly retry a potentially successful Post.
Do not store Facebook session cookies in WordPress or duplicate the GitHub code
without first resolving its licensing. Group rules still apply; 50/day is not a
guarantee against account restrictions.

Validation: `php tests/facebook-group-plan.php`, plus PHP lint on the changed files.

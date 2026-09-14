# Check ZIPs error reporting 3.8.26

The browser discarded structured WordPress JSON errors on non-2xx HTTP responses,
so history validation or database errors returned as HTTP 400 became a generic
server-error message. Decode the response and expose its safe message through
textContent. Preserve non-retryable handling of 4xx responses, authentication
handling and retryable handling of network/429/5xx errors. An HTTP 400 with a bare
WordPress `0` now identifies an unavailable AJAX action.

New ZIP checks label the existing bulk queue as previous state instead of implying
the new request processed 250 ZIPs. Queue/session data and saved leads are untouched.
ZIP parsing supports Unicode whitespace from copied lists; invalid ZIP and excess
count messages identify the input issue. The 250 unique ZIP limit remains.

The original live server's specific rejection is not known from the screenshot.
This patch reveals that diagnostic; it does not claim to fix an unobserved database
or security-rule failure. Update WordPress only, refresh, and rerun Check ZIPs.

Validation: 16 response checks, 19 history checks, 3646 existing browser/UI checks.

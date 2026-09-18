# SEO OS client directory fix — 3.10.1

## Confirmed cause

The hub's Money Management page identifies Estefania Erys Creative as an Analytics record without a billing profile. Its Analytics identity is `estefania-erys-creative`. Analytics combines shared profiles and legacy Analytics configurations, while SEO OS previously read only `wnq_clients`. The agent-key dropdown consequently omitted Erys. The existing client form requires an email to complete its shared profile.

## Changes

- SEO OS client lists, agent-key selection, and related SEO tools use a read-only directory including shared profiles and active legacy Analytics records.
- Exact client IDs remain unchanged. One unambiguous legacy Analytics mapping is not shown twice. Ambiguous mappings are not merged.
- Existing legacy profile URLs retain exact-ID lookup. No billing record, login, credential, payment, or Analytics configuration is created or changed by directory reads.
- Key-generation requests validate the selected identity; missing fields, unknown clients, and storage failures receive visible errors. Failed key creation no longer creates an empty SEO profile.
- API Management links to the shared Add New Client form. Legacy SEO profiles link to the existing completion form.

## Immediate Erys repair without installing this update

Open Money Management → Complete shared client profile beside Estefania Erys Creative. Supply the actual contact email and intended client details; preserve the prefilled client ID and Google settings. Configure billing only with known rates/dates. After saving, Erys should appear in the current SEO OS client/key dropdown.

## Validation

Passed local PHP syntax checks and regression scripts:
- tests/seo-client-directory.php
- tests/shared-client-directory.php
- tests/client-schema.php
- tests/blog-scheduler-regression.php
- tests/analytics-regression.php
- tests/monthly-bookkeeping.php
- tests/service-city-blueprint-regression.php

The new regression test uses the real Client and Analytics models with a database fixture; key persistence is stubbed. No production API key was generated, and the update has not been installed on the live hub.

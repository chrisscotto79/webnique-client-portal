Golden Web Marketing Client Portal

Contributors: goldenwebmarketing
Author: Christopehr Scotto
Tags: client portal, seo dashboard, analytics, stripe, firebase, agency
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 2.4.53
License: Proprietary
License URI: https://goldenwebmarketing.com

Description

Golden Web Marketing Client Portal is a custom WordPress plugin built exclusively for Golden Web Marketing to provide clients with a centralized, secure dashboard for viewing SEO performance, subscription status, tasks, and direct communication.

This plugin is designed as a multi-tenant client portal hosted by Golden Web Marketing, where each client logs in using standard WordPress credentials and accesses only their own data.

The portal is intentionally built in phases, starting with a clean v1 foundation and expanding over time to support advanced SEO analytics, reporting, audits, and Google Ads.

Elementor Shortcode

Add either `[wnq_portal]` or `[gwm_client_portal]` to an Elementor Shortcode widget. Logged-in users must have a `wnq_client_id` value assigned to their WordPress user profile.

The client dashboard includes account health, reports, a basic customer CRM, messages, work progress, billing status, learning resources, and the stored business profile. Golden Web Marketing staff can review all client portal activity under Golden Web Marketing Portal > Client Portal Dashboard.

Core Objectives

Provide each client with one secure login per business

Centralize SEO performance visibility

Display subscription and billing status

Create a single place for client ↔ Golden Web Marketing communication

Replace scattered emails, PDFs, and screenshots with a live dashboard

Build a foundation that can scale to hundreds of clients without refactoring

What This Plugin Is (v1)

✔ A client-facing dashboard embedded in WordPress
✔ A React-powered UI rendered via shortcode
✔ A REST API-driven backend
✔ A Firebase-backed data layer (v1 storage)
✔ A read-only mirror of Stripe subscription status
✔ A controlled, permission-based system (1 client = 1 login)

What This Plugin Is NOT (v1)

✘ Not a public plugin
✘ Not a theme
✘ Not a CRM replacement
✘ Not a Google Ads manager (Ads will come later)
✘ Not a billing system (Stripe is source-of-truth)
✘ Not running live API calls on page load

Initial Feature Set (v1)
Client-Facing

- Dashboard overview (high-level SEO status)

- Subscription status (plan, billing state, renewal)

- SEO analytics placeholders (Search Console / GA4 later)

- Task list (client requests + Golden Web Marketing actions)

- Messaging threads (ticket-style communication)

- Account settings (basic)

Admin-Facing (Golden Web Marketing Only)

- Client management

- User-to-client assignment

- Integration configuration

- Manual sync controls (future)

- Logging/debug utilities

- Authentication & Access Model

- Clients log in using standard WordPress usernames/passwords

Each WordPress user is mapped to exactly one client business

Access is enforced via:

WordPress authentication

Custom permission checks at the REST layer

No client can view or access another client’s data

Data Storage (v1)

Primary storage: Firebase (Firestore / Realtime DB)

WordPress stores only:

User accounts

Client ownership mapping (via user meta)

This abstraction allows future migration to:

Custom MySQL tables

External data warehouses

Hybrid storage models

External Integrations (Planned)

Note: API keys and integrations are intentionally deferred until the foundation is stable.

Planned integrations include:

Google Search Console API

Google Analytics 4 Data API

Stripe API (read-only subscription status)

PageSpeed Insights API

Rank tracking provider (TBD)

Google Ads API (future phase)

Architecture Overview
Plugin Structure
webnique-client-portal/
├── webnique-client-portal.php
├── readme.txt
├── includes/
│   ├── Core/
│   ├── Controllers/
│   ├── Services/
│   └── Views/
├── public/
├── admin/
└── assets/

Key Design Principles

Separation of concerns

No logic in views

No direct API calls from the frontend

All external data cached and normalized

REST-first architecture

Incremental development by phase

Rendering the Client Portal

The client dashboard is rendered using a shortcode:

[wnq_portal]


This shortcode outputs a minimal HTML shell where the JavaScript application mounts.

Performance Strategy

No live Google API calls on page load

Data is pre-synced and cached

Dashboards read from stored snapshots

Background jobs handle data collection

Security Considerations

Strict tenant isolation

Permission checks on every REST request

No API secrets exposed to the frontend

Future support for audit logs and access tracking

Versioning Strategy

0.1.x – Foundation & scaffolding

0.2.x – Firebase + basic data flows

0.3.x – SEO metrics ingestion

0.4.x – Reporting & exports

1.0.0 – Stable production release

Roadmap (High-Level)

Firebase integration

Stripe subscription mirroring

Search Console metrics

GA4 metrics

SEO task automation

Rank tracking

Monthly reporting snapshots

Google Ads performance

Support

This plugin is private and maintained internally by Golden Web Marketing.

For development questions, roadmap changes, or feature requests, contact the Golden Web Marketing development team.

Changelog
2.4.53 - Per-Client Ads and Payment Alerts

- Added a separate rolling 30-day Google Ads spend threshold to every linked client Ads account
- Added per-client next payment due date, reminder lead time, and payment notification controls
- Sends upcoming, due-today, and overdue payment reminders to the internal Telegram group
- Advances the next payment date automatically after a successful payment using the client's billing cycle
- Added the read-only `/billing` Telegram command for upcoming client payment dates

2.4.52 - Agency Task Alerts and Telegram Commands

- Removed Telegram alerts for client CRM leads, jobs, conversions, and client-company follow-ups
- Replaced the CRM follow-up digest with Golden Web Marketing task alerts from the Tasks dashboard
- Added read-only Telegram commands for open tasks, today's tasks, overdue tasks, Ads spend, client requests, and system status
- Automatically creates agency tasks for new client requests, learning requests, and new support tickets without duplicating tasks for replies
- Added compact Telegram formatting and action buttons so internal alerts no longer expose long raw WordPress URLs

2.4.51 - Telegram Notification System

- Added configurable Telegram alerts for new CRM records, lead conversions, client messages, service requests, learning requests, payments, Ads thresholds, Ads connection problems, and overdue follow-ups
- Added a locked daily alert checker with threshold-crossing state and duplicate suppression
- Added signed Stripe webhook support for successful and failed payment events
- Added notification health, event toggles, Ads threshold controls, and a manual Run Alert Checks action in WordPress settings
- Connected successful CRM, request, message, learning, and manually recorded payment actions to the alert dispatcher

2.4.50 - Telegram Group Discovery

- Added a secure server-side action that finds Telegram groups recently connected to the saved bot
- Automatically saves the chat ID when exactly one group is found and offers selection buttons when multiple groups are returned
- Replaced Telegram's vague chat-not-found response with clear bot membership and group command instructions

2.4.49 - Telegram Notification Settings

- Added secure server-side settings for a Telegram bot token and private group chat ID
- Added an enable switch, connection status, masked secret handling, and token clearing control
- Added a Save and Test Telegram action that sends a real connection message to the configured group
- Added a reusable Telegram notifier service for upcoming CRM, payment, request, and Ads alerts

2.4.48 - Ads No-Account State

- Added a clear client-facing message when no Google Ads account is linked
- Treats an unmatched client as likely not running Ads instead of presenting a setup error
- Keeps administrator connection controls available for clients who begin advertising later
- Exposes only the linked/not-linked state to client users, never MCC account details

2.4.47 - Locked Ads Connection

- Removed the Google Ads Billing link because client users do not have direct billing access
- Replaced the open account selector with a locked linked-account summary after a client account is assigned
- Moved account reassignment into a collapsed administrator-only control
- Clarified that payment activity remains private and managed by Golden Web Marketing

2.4.46 - Ads Account Isolation and Billing Setup

- Restricted the Ads account-link endpoint to Golden Web Marketing portal administrators at the REST permission layer
- Clarified that client users are locked to their assigned portal account and never receive the MCC account list
- Added an admin-only Billing Account card using Google Ads billing setup metadata
- Added a safe link to Google Ads for credit, payment method, and payment activity that the Ads API does not expose

2.4.45 - Google Ads Workspace Redesign

- Split the Ads screen into focused Overview, Campaigns, Search Insights, Pages and Devices, and Connection views
- Limited long report tables to 12 visible rows with Show all and Show fewer controls
- Reworked the Ads summary cards, account status, responsive layout, and connection diagnostics
- Uses a successful Google Ads manager-account query to verify the connection instead of blocking reports on a stale portal access label

2.4.44 - Google Ads QA and UI Polish

- Added clearer Google Ads connection states, refresh controls, and setup diagnostics
- Improved the internal Ads report layout with cleaner KPI cards, empty states, and account matching status
- Hardened Google Ads account discovery caching and de-duplicated API error messages

2.4.43 - Google Ads Internal Reporting

- Added a complete server-side Google Ads OAuth settings checklist and connection test
- Removed misleading API-key and service-account requirements from the Ads connection
- Added MCC child-account discovery and automatic client account matching
- Added internal campaign, search term, keyword, landing page, device, and conversion reporting
- Added 15-minute report caching and manual refresh support to control API usage
- Kept live Google Ads data restricted to administrators under the approved internal-reporting access

2.4.42 - Client Reports, Photos, and Notifications

- Added secure inline previews for saved job photos and image attachments
- Added custom date ranges, a complete CRM overview, and CSV exports to CRM Reports
- Added an optional notification ring and automatic notification badge polling
- Restyled the Request Center with neutral black and gold interaction states
- Connected new requests to WordPress admin badges, admin notices, backend review, and detailed email alerts
- Added clear request submission progress, success confirmation, and recoverable errors

2.4.41 - Draggable Opportunity Workflow

- Renamed the client navigation item to SEO Reports while preserving the separate CRM Reports workspace
- Removed Marketing Work History from the Overview dashboard
- Added drag-and-drop opportunity movement with saved pipeline stage updates
- Kept the stage selector as a keyboard, touch, and mobile fallback
- Removed advertising spend and cost metrics from client-facing Ads API responses

2.4.40 - Custom Opportunity Pipelines

- Added a dedicated Opportunities workspace with a visual pipeline board
- Added per-client pipeline stages with custom names, colors, and ordering
- Added safe stage movement, lead editing, and lead-to-job conversion from pipeline cards
- Connected opportunity stages to lead forms, directories, reports, and dashboard snapshots
- Modernized the portal navigation, shared surfaces, controls, and responsive layouts

2.4.39 - Connected CRM Workflow and Portal UX

- Added a full-screen portal mode and removed Marketing Work from sidebar navigation
- Simplified CRM records to Leads and Jobs with a dedicated Convert to Job action
- Added connected CRM, business profile, and notification settings
- Added a notification center for support replies, follow-ups, jobs, and reports
- Rebuilt the calendar as a responsive monthly schedule grid
- Fixed saved records being hidden by stale filters and normalized legacy customer records

2.4.38 - Dedicated GBP OAuth Validation

- Stopped silently reusing Google Ads OAuth credentials for Business Profile access
- Added complete credential-pair validation before GBP OAuth settings can be saved
- Added setup guidance for deleted OAuth clients and incomplete credentials
- Clear stale connection data when the GBP OAuth application changes

2.4.37 - Google Business Profile Connection and Publishing

- Added agency-level Google OAuth with offline access for the GBP Scheduler
- Added account and location sync for every Business Profile the connected Google user can manage
- Added automatic client-to-location matching with a manual mapping override
- Added live scheduled and manual GBP publishing with duplicate-post protection
- Added complete Event and Offer post fields, API diagnostics, and connection status
- Kept OAuth secrets and refresh tokens server-side with nonce, capability, and state validation

2.4.36 - Auto-Blogger Rate Limit Recovery

- Added a conservative Groq tokens-per-minute budget and provider cooldown handling
- Changed AI 429 responses from permanent failures into automatic deferred retries
- Automatically requeues existing failed blog posts whose errors were caused by rate limits
- Reduced Groq blog output reservations so complete posts fit within the account token limit
- Updated manual publish and generation actions to recognize scheduled retries as successful queue operations

2.4.35 - Auto-Blogger Queue Reliability

- Changed automatic publishing to process one due post per worker request and chain remaining posts
- Added a run lock and atomic per-post claims to prevent overlapping cron/manual publishing
- Added recovery for posts left in generating or publishing after an interrupted request
- Increased the client-site publishing timeout and added expandable full error details in the queue
- Updated due-date checks to use the WordPress site timezone

2.4.34 - SEO and CRM Report Split

- Added a dedicated CRM Reports sidebar section with Leads, Jobs, Calendar, and Follow-ups report panels
- Kept the client Monthly SEO Reports archive separate from CRM reports
- Normalized SEO report titles to "Monthly Report" and simplified period display to month-only labels

2.4.33 - CRM Visual Polish

- Simplified the CRM KPI cards by removing the abbreviation circles and tightening the spacing
- Centered the Opportunity Overview donut value and label inside the chart
- Improved main-content button and link hover contrast so text stays readable

2.4.32 - Report Archive and Ads Coming Soon

- Restored the Reports sidebar page to the SEO OS report archive with View Full Report and Download PDF actions
- Added the Learning Center back to the client portal sidebar
- Changed the Ads page to a simple coming-soon state until Google Ads reporting work resumes
- Kept the focused CRM Overview, Leads, Jobs, Calendar, Follow-ups, Marketing Work, Billing, and Settings pages intact

2.4.31 - Focused CRM Route Layouts

- Split the CRM UI so Overview is the only page that renders the full dashboard header, KPI cards, charts, activity, and marketing preview
- Added focused Leads, Jobs, Calendar, Follow-ups, Reports, Marketing Work, and Settings page layouts with route-specific controls and empty states
- Reworked job and follow-up tables to fit inside their cards and avoid horizontal overflow on the Jobs page
- Preserved existing CRM save, edit, follow-up, marketing work, filtering, and sidebar navigation behavior

2.4.30 - Premium CRM Dashboard UI

- Reworked the client portal sidebar around CRM-first navigation for Overview, Leads, Jobs, Calendar, Follow-ups, Reports, Marketing Work, Ads, Billing, and Settings
- Added a polished CRM dashboard with KPI cards, opportunity overview, pipeline snapshot, follow-ups, revenue trend, lead source report, recent activity, and marketing work history
- Added a current account label for non-admin portal users and kept the admin view-as-client selector
- Improved responsive CRM dashboard behavior while preserving existing lead/job forms, filters, records, reports, and marketing work logging

2.4.29 - Leads & Jobs CRM Improvements

- Renamed the client-facing CRM area to Leads & Jobs
- Repaired stale CRM table saves and added safer private/admin field handling
- Added cleaner lead/job statuses, follow-up actions, lead source reporting, and marketing work logging
- Improved dashboard cards, reports, empty states, and mobile table behavior

2.4.28 - CRM QA and UI Polish

- Hardened CRM table repair for partial deployments and missing customer/job columns
- Improved CRM save feedback, date handling, job/revenue labels, and visible portal versioning
- Refined CRM controls and form styling for a cleaner client-facing workflow

2.4.27 - CRM Database Migration Repair

- Added an explicit CRM customer table migration for older installs missing job/revenue columns
- Repairs missing fields such as record type, job dates, revenue, cost, notes, and upload columns before saving

2.4.26 - CRM Save Hardening

- Added a visible portal version label beside the signed-in user
- Switched normal CRM saves to JSON to avoid multipart body parsing issues
- Forced CRM table checks during schema setup and exposed admin database save errors

2.4.25 - CRM Save and Scheduling Fixes

- Hardened CRM record saving for frontend Elementor shortcode forms
- Improved CRM counters, scheduled job tabs, money parsing, and upload validation
- Fixed protected CRM attachment downloads and refreshed portal assets

2.4.23 - Support Tickets and Request Center

- Added searchable and filterable support tickets with file attachments
- Added expected response times, client reopen controls, and reply email notifications
- Added a dynamic Request Center for website edits, new pages, blogs, report questions, and strategy calls
- Added agency-side request status management, request counts, and client status notifications

2.4.22 - Client Portal Experience

- Replaced the sidebar wordmark with the Golden Web Marketing logo
- Added threaded support tickets with categories, priorities, statuses, and admin replies
- Added a learning center course library and client learning request form
- Made public business profile information editable by the linked client
- Refined the responsive client portal layout and form styling

2.1.0 - SEO Operating System

Complete AI-powered SEO OS integrated into hub:
- 8 new database tables for SEO OS
- SEO Hub admin menu (Dashboard, Clients, Keywords, Service City Pages, Technical Audits, Reports, API Management, AI Settings)
- Golden Web Marketing SEO Agent client plugin (separate installable)
- Groq/OpenAI/Together AI modular engine with free tier support
- Nightly audit system via WP-Cron (missing H1, thin content, schema, alt text, declining ranks)
- Monthly report auto-generation with AI executive summaries
- REST API for client plugin: /wp-json/wnq/v1/agent/*
- Keyword tracking with cluster grouping and position history
- Service + City CSV import workflow for one-at-a-time Elementor draft child pages
- Blog scheduler with Elementor draft/publish support
- SEO activity log for full traceability

2.0.0

Full client portal system with analytics, SEO tracking, tasks, billing

0.1.0

Initial plugin scaffolding

SEO OS Architecture Notes

Hub (current test URL: https://wordpress-1502434-5752021.cloudwaysapps.com/):
  Production hub: Golden Web Marketing SEO OS hub (once DNS is pointed)
  All SEO intelligence, analysis, AI, automation, reporting runs here
  Hub URL is always = site_url() of the WordPress install hosting this plugin

Client Sites (Golden Web Marketing SEO Agent plugin):
  Data collection and relay only — no SEO logic on client sites
  Authenticates via API key (X-WNQ-Api-Key header)
  Syncs twice daily via WP-Cron

AI Providers (free-tier first):
  Groq: console.groq.com — 14,400 req/day free
  Together AI: api.together.xyz — $25 free credit
  OpenAI: platform.openai.com — paid

Phase Roadmap:
  Phase 1 (Complete) - Core automation: content gaps, meta tags, schema, audits, reports
  Phase 2 (Planned)  - Advanced keyword clustering, GSC auto-sync, forecasting
  Phase 3 (Planned)  - Predictive SEO, white-label client portal, email delivery

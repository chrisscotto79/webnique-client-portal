WebNique Client Portal

Contributors: webnique
Author: Christopehr Scotto
Tags: client portal, seo dashboard, analytics, stripe, firebase, agency
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 2.2.0
License: Proprietary
License URI: https://web-nique.com

Description

WebNique Client Portal is a custom WordPress plugin built exclusively for WebNique to provide clients with a centralized, secure dashboard for viewing SEO performance, subscription status, tasks, and direct communication.

This plugin is designed as a multi-tenant client portal hosted on web-nique.com, where each client logs in using standard WordPress credentials and accesses only their own data.

The portal is intentionally built in phases, starting with a clean v1 foundation and expanding over time to support advanced SEO analytics, reporting, audits, and Google Ads.

Core Objectives

Provide each client with one secure login per business

Centralize SEO performance visibility

Display subscription and billing status

Create a single place for client ↔ WebNique communication

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

- Task list (client requests + WebNique actions)

- Messaging threads (ticket-style communication)

- Account settings (basic)

Admin-Facing (WebNique Only)

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

This plugin is private and maintained internally by WebNique.

For development questions, roadmap changes, or feature requests, contact the WebNique development team.

Changelog
2.1.0 - SEO Operating System

Complete AI-powered SEO OS integrated into hub:
- 8 new database tables for SEO OS
- SEO Hub admin menu (Dashboard, Clients, Keywords, Content Automation, Technical Audits, Reports, API Management, Settings)
- WebNique SEO Agent client plugin (separate installable)
- Groq/OpenAI/Together AI modular engine with free tier support
- Nightly audit system via WP-Cron (missing H1, thin content, schema, alt text, declining ranks)
- Monthly report auto-generation with AI executive summaries
- REST API for client plugin: /wp-json/wnq/v1/agent/*
- Keyword tracking with cluster grouping and position history
- Content gap analysis engine
- AI content job queue with manual approval workflow
- Automation activity log for full traceability

2.0.0

Full client portal system with analytics, SEO tracking, tasks, billing

0.1.0

Initial plugin scaffolding

SEO OS Architecture Notes

Hub (current test URL: https://wordpress-1502434-5752021.cloudwaysapps.com/):
  Production hub: web-nique.com (once DNS is pointed)
  All SEO intelligence, analysis, AI, automation, reporting runs here
  Hub URL is always = site_url() of the WordPress install hosting this plugin

Client Sites (WebNique SEO Agent plugin):
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

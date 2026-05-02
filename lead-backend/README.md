# WebNique Lead Backend

Backend for scalable ZIP sweeps. Maps discovery is self-hosted through Puppeteer and website parsing uses Cheerio; no paid scraper API is required.

## Run Locally

```bash
cp .env.example .env
docker compose up -d
psql "$DATABASE_URL" -f db/schema.sql
npm install
npm run dev
npm run worker
```

Existing Phase 1 database:

```bash
psql "$DATABASE_URL" -f db/migrations/002_phase2_operations.sql
psql "$DATABASE_URL" -f db/migrations/003_no_paid_maps_provider.sql
psql "$DATABASE_URL" -f db/migrations/004_use_puppeteer_cheerio.sql
```

WordPress should call this service over REST. WordPress starts jobs, polls progress, and imports completed leads. This backend owns Maps discovery, queueing, retries, enrichment, and storage.

For scale, keep `MAX_ZIP_CONCURRENCY` conservative and increase slowly. Self-hosted Google Maps scraping is cheaper than paid APIs, but it can still be rate limited; run the worker on infrastructure where you can control browser dependencies, outbound IPs, and job concurrency.

## API

`POST /v1/jobs`

```json
{
  "keyword": "plumbing",
  "zips": ["32825", "32202"],
  "source": "puppeteer",
  "filters": {
    "maxReviews": 50,
    "requireWebsite": true,
    "requirePhone": false
  }
}
```

`GET /v1/jobs/:jobId`

`GET /v1/jobs/:jobId/leads`

`POST /v1/jobs/:jobId/cancel`

`POST /v1/jobs/:jobId/retry-failed`

`POST /v1/jobs/:jobId/mark-imported`

`GET /v1/jobs/:jobId/export.csv`

## Standalone Scraper Script

Run one ZIP outside WordPress:

```bash
npm run scrape:zip -- --keyword "plumbing" --zip 32825 --max-reviews 50
```

This uses Puppeteer for Google Maps scrolling and Cheerio for homepage/contact-page parsing.

All routes require:

```text
Authorization: Bearer YOUR_API_KEY
```

## Phase 2 Operations

- Failed ZIPs are marked and counted so jobs do not hang forever.
- Failed ZIPs can be retried without re-running completed ZIPs.
- Completed jobs can be imported into WordPress from the plugin's Backend Jobs tab.
- CSV export is available through the backend and proxied by WordPress so the API key is never exposed in the browser.
- Leads include `lead_score` and `score_reasons` for prioritization.

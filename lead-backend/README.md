# WebNique Lead Backend

Phase 1 backend for scalable ZIP sweeps.

## Run Locally

```bash
cp .env.example .env
docker compose up -d
psql "$DATABASE_URL" -f db/schema.sql
npm install
npm run dev
npm run worker
```

WordPress should call this service over REST. WordPress starts jobs, polls progress, and imports completed leads. This backend owns Maps discovery, queueing, retries, enrichment, and storage.

## API

`POST /v1/jobs`

```json
{
  "keyword": "plumbing",
  "zips": ["32825", "32202"],
  "source": "outscraper",
  "filters": {
    "maxReviews": 50,
    "requireWebsite": true,
    "requirePhone": false
  }
}
```

`GET /v1/jobs/:jobId`

`GET /v1/jobs/:jobId/leads`

All routes require:

```text
Authorization: Bearer YOUR_API_KEY
```

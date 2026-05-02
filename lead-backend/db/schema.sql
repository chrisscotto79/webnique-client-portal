CREATE EXTENSION IF NOT EXISTS "pgcrypto";

CREATE TABLE IF NOT EXISTS lead_jobs (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  source VARCHAR(50) NOT NULL DEFAULT 'outscraper',
  keyword VARCHAR(255) NOT NULL,
  state VARCHAR(50) NOT NULL DEFAULT 'queued',
  total_zips INT NOT NULL DEFAULT 0,
  completed_zips INT NOT NULL DEFAULT 0,
  total_found INT NOT NULL DEFAULT 0,
  total_saved INT NOT NULL DEFAULT 0,
  imported_to_wordpress BOOLEAN NOT NULL DEFAULT FALSE,
  error_count INT NOT NULL DEFAULT 0,
  created_by VARCHAR(255),
  filters JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  started_at TIMESTAMP,
  completed_at TIMESTAMP,
  canceled_at TIMESTAMP
);

CREATE TABLE IF NOT EXISTS lead_job_zips (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  job_id UUID NOT NULL REFERENCES lead_jobs(id) ON DELETE CASCADE,
  zip VARCHAR(20) NOT NULL,
  state VARCHAR(50) NOT NULL DEFAULT 'queued',
  attempts INT NOT NULL DEFAULT 0,
  found_count INT NOT NULL DEFAULT 0,
  saved_count INT NOT NULL DEFAULT 0,
  error TEXT,
  started_at TIMESTAMP,
  completed_at TIMESTAMP,
  UNIQUE(job_id, zip)
);

CREATE TABLE IF NOT EXISTS leads (
  id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
  job_id UUID REFERENCES lead_jobs(id) ON DELETE SET NULL,
  source VARCHAR(50) NOT NULL,
  source_place_id VARCHAR(255),
  business_name VARCHAR(255) NOT NULL,
  industry VARCHAR(255),
  phone VARCHAR(100),
  email VARCHAR(255),
  email_source TEXT,
  website TEXT,
  address TEXT,
  city VARCHAR(150),
  state VARCHAR(50),
  zip VARCHAR(20),
  rating NUMERIC(3,1),
  review_count INT DEFAULT 0,
  google_maps_url TEXT,
  facebook TEXT,
  instagram TEXT,
  linkedin TEXT,
  youtube TEXT,
  tiktok TEXT,
  lead_score INT NOT NULL DEFAULT 0,
  status VARCHAR(50) NOT NULL DEFAULT 'new',
  raw JSONB NOT NULL DEFAULT '{}'::jsonb,
  created_at TIMESTAMP NOT NULL DEFAULT NOW(),
  updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX IF NOT EXISTS leads_unique_place_id
ON leads(source, source_place_id)
WHERE source_place_id IS NOT NULL AND source_place_id <> '';

CREATE INDEX IF NOT EXISTS leads_job_id ON leads(job_id);
CREATE INDEX IF NOT EXISTS leads_status ON leads(status);
CREATE INDEX IF NOT EXISTS leads_review_count ON leads(review_count);

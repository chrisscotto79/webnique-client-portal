ALTER TABLE lead_jobs ALTER COLUMN source SET DEFAULT 'playwright';

UPDATE lead_jobs
SET source = 'playwright'
WHERE source IN ('outscraper', 'serpapi');

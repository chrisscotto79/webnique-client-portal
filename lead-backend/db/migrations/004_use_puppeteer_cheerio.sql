ALTER TABLE lead_jobs ALTER COLUMN source SET DEFAULT 'puppeteer';

UPDATE lead_jobs
SET source = 'puppeteer'
WHERE source = 'playwright';

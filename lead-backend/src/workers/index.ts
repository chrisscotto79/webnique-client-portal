import { Worker } from 'bullmq';
import { config, requireEnv } from '../config.js';
import { query, one } from '../db.js';
import { redis } from '../redis.js';
import { zipQueue, enrichQueue } from '../queues.js';
import { searchGoogleMapsWithPlaywright } from '../providers/playwrightMaps.js';
import { upsertLead, updateLeadEnrichment } from '../services/leads.js';
import { enrichWebsite } from '../services/enrichment.js';

requireEnv();

new Worker('lead-sweep', async job => {
  const { jobId } = job.data as { jobId: string };
  await query("UPDATE lead_jobs SET state = 'running', started_at = COALESCE(started_at, NOW()) WHERE id = $1", [jobId]);

  const zips = await query<{ id: string; zip: string }>('SELECT id, zip FROM lead_job_zips WHERE job_id = $1', [jobId]);
  for (const row of zips) {
    await zipQueue.add('zip:search', { jobId, zipJobId: row.id, zip: row.zip });
  }
}, { connection: redis, concurrency: 1 });

const zipWorker = new Worker('zip-search', async job => {
  const { jobId, zipJobId, zip } = job.data as { jobId: string; zipJobId: string; zip: string };
  const parent = await one<any>('SELECT * FROM lead_jobs WHERE id = $1', [jobId]);
  if (!parent || parent.state === 'canceled') return;

  const filters = parent.filters || {};
  const maxReviews = Number(filters.maxReviews || 50);
  const requireWebsite = filters.requireWebsite !== false;
  const requirePhone = filters.requirePhone === true;

  await query(
    "UPDATE lead_job_zips SET state = 'running', attempts = attempts + 1, started_at = COALESCE(started_at, NOW()) WHERE id = $1",
    [zipJobId]
  );

  const businesses = await searchGoogleMapsWithPlaywright(`${parent.keyword} in ${zip}`, maxReviews);
  let saved = 0;

  for (const business of businesses) {
    if (requireWebsite && !business.website) continue;
    if (requirePhone && !business.phone) continue;

    const lead = await upsertLead({
      jobId,
      source: parent.source,
      keyword: parent.keyword,
      business
    });

    if (lead) {
      saved++;
      if (lead.website) {
        await enrichQueue.add('lead:enrich', { leadId: lead.id, website: lead.website });
      }
    }
  }

  await query(
    `UPDATE lead_job_zips
     SET state = 'completed', found_count = $2, saved_count = $3, completed_at = NOW()
     WHERE id = $1`,
    [zipJobId, businesses.length, saved]
  );

  await query(
    `UPDATE lead_jobs SET
      completed_zips = completed_zips + 1,
      total_found = total_found + $2,
      total_saved = total_saved + $3
     WHERE id = $1`,
    [jobId, businesses.length, saved]
  );

  await maybeCompleteJob(jobId);
}, { connection: redis, concurrency: config.maxZipConcurrency });

zipWorker.on('failed', async (job, err) => {
  if (!job) return;
  const attempts = job.opts.attempts || 1;
  if (job.attemptsMade < attempts) return;

  const { jobId, zipJobId } = job.data as { jobId: string; zipJobId: string };
  await query(
    "UPDATE lead_job_zips SET state = 'failed', error = $2, completed_at = NOW() WHERE id = $1",
    [zipJobId, err.message]
  );
  await query(
    `UPDATE lead_jobs SET
      completed_zips = completed_zips + 1,
      error_count = error_count + 1
     WHERE id = $1`,
    [jobId]
  );
  await maybeCompleteJob(jobId);
});

new Worker('lead-enrich', async job => {
  const { leadId, website } = job.data as { leadId: string; website: string };
  const result = await enrichWebsite(website);
  await updateLeadEnrichment(leadId, result);
}, { connection: redis, concurrency: config.maxEnrichConcurrency });

async function maybeCompleteJob(jobId: string): Promise<void> {
  const row = await one<{ total_zips: number; completed_zips: number }>(
    'SELECT total_zips, completed_zips FROM lead_jobs WHERE id = $1',
    [jobId]
  );
  if (row && row.completed_zips >= row.total_zips) {
    await query(
      `UPDATE lead_jobs
       SET state = CASE WHEN error_count > 0 THEN 'completed_with_errors' ELSE 'completed' END,
           completed_at = NOW()
       WHERE id = $1 AND state != 'canceled'`,
      [jobId]
    );
  }
}

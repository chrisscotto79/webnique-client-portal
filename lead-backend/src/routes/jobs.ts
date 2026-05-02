import type { FastifyInstance } from 'fastify';
import { one, query } from '../db.js';
import { requireApiKey } from '../auth.js';
import { sweepQueue, zipQueue } from '../queues.js';
import type { CreateJobInput } from '../types.js';

export async function jobRoutes(app: FastifyInstance) {
  app.get('/v1/jobs', { preHandler: requireApiKey }, async req => {
    const requestQuery = req.query as { limit?: string };
    const limit = Math.max(1, Math.min(100, Number(requestQuery.limit || 25)));
    const jobs = await query(
      `SELECT id, source, keyword, state, total_zips, completed_zips, total_found,
              total_saved, error_count, imported_to_wordpress, created_at, started_at, completed_at
       FROM lead_jobs
       ORDER BY created_at DESC
       LIMIT $1`,
      [limit]
    );
    return { jobs };
  });

  app.post('/v1/jobs', { preHandler: requireApiKey }, async (req, reply) => {
    const body = req.body as CreateJobInput;
    const keyword = String(body.keyword || '').trim();
    const zips = Array.from(new Set((body.zips || []).map(zip => String(zip).trim()).filter(Boolean)));
    const source = body.source || 'outscraper';
    const filters = {
      maxReviews: body.filters?.maxReviews ?? 50,
      requireWebsite: body.filters?.requireWebsite ?? true,
      requirePhone: body.filters?.requirePhone ?? false
    };

    if (!keyword || !zips.length) {
      return reply.code(400).send({ error: 'keyword and zips are required' });
    }

    const job = await one<{ id: string }>(
      `INSERT INTO lead_jobs (keyword, source, total_zips, filters, created_by)
       VALUES ($1,$2,$3,$4,$5)
       RETURNING id`,
      [keyword, source, zips.length, JSON.stringify(filters), body.createdBy || 'wordpress']
    );

    if (!job) return reply.code(500).send({ error: 'Could not create job' });

    for (const zip of zips) {
      await query('INSERT INTO lead_job_zips (job_id, zip) VALUES ($1,$2) ON CONFLICT DO NOTHING', [job.id, zip]);
    }

    await sweepQueue.add('sweep:start', { jobId: job.id });

    return reply.send({ jobId: job.id, state: 'queued', totalZips: zips.length });
  });

  app.get('/v1/jobs/:jobId', { preHandler: requireApiKey }, async req => {
    const { jobId } = req.params as { jobId: string };
    const job = await one('SELECT * FROM lead_jobs WHERE id = $1', [jobId]);
    return { job };
  });

  app.get('/v1/jobs/:jobId/leads', { preHandler: requireApiKey }, async req => {
    const { jobId } = req.params as { jobId: string };
    const leads = await query('SELECT * FROM leads WHERE job_id = $1 ORDER BY created_at DESC LIMIT 10000', [jobId]);
    return { leads };
  });

  app.post('/v1/jobs/:jobId/cancel', { preHandler: requireApiKey }, async req => {
    const { jobId } = req.params as { jobId: string };
    await query("UPDATE lead_jobs SET state = 'canceled', canceled_at = NOW() WHERE id = $1", [jobId]);
    return { ok: true };
  });

  app.post('/v1/jobs/:jobId/retry-failed', { preHandler: requireApiKey }, async req => {
    const { jobId } = req.params as { jobId: string };
    const failed = await query<{ id: string; zip: string }>(
      "SELECT id, zip FROM lead_job_zips WHERE job_id = $1 AND state = 'failed'",
      [jobId]
    );

    if (!failed.length) return { ok: true, retried: 0 };

    await query(
      `UPDATE lead_job_zips
       SET state = 'queued', error = NULL, completed_at = NULL
       WHERE job_id = $1 AND state = 'failed'`,
      [jobId]
    );
    await query(
      `UPDATE lead_jobs
       SET state = 'running',
           completed_zips = GREATEST(completed_zips - $2, 0),
           error_count = GREATEST(error_count - $2, 0),
           completed_at = NULL
       WHERE id = $1`,
      [jobId, failed.length]
    );

    for (const row of failed) {
      await zipQueue.add('zip:search', { jobId, zipJobId: row.id, zip: row.zip });
    }

    return { ok: true, retried: failed.length };
  });

  app.post('/v1/jobs/:jobId/mark-imported', { preHandler: requireApiKey }, async req => {
    const { jobId } = req.params as { jobId: string };
    await query('UPDATE lead_jobs SET imported_to_wordpress = TRUE WHERE id = $1', [jobId]);
    return { ok: true };
  });

  app.get('/v1/jobs/:jobId/export.csv', { preHandler: requireApiKey }, async (req, reply) => {
    const { jobId } = req.params as { jobId: string };
    const leads = await query('SELECT * FROM leads WHERE job_id = $1 ORDER BY created_at DESC LIMIT 10000', [jobId]);
    const headers = [
      'Company Name', 'Email', 'Phone', 'Website', 'Address', 'City', 'State', 'Postal Code',
      'Industry', 'Stars', 'Review Count', 'Lead Score', 'Score Reasons', 'Facebook',
      'Instagram', 'LinkedIn', 'YouTube', 'TikTok', 'Google Maps URL'
    ];
    const rows = leads.map((lead: any) => [
      lead.business_name, lead.email, lead.phone, lead.website, lead.address, lead.city, lead.state, lead.zip,
      lead.industry, lead.rating, lead.review_count, lead.lead_score, (lead.score_reasons || []).join(' | '),
      lead.facebook, lead.instagram, lead.linkedin, lead.youtube, lead.tiktok, lead.google_maps_url
    ]);
    const csv = [headers, ...rows].map(row => row.map(csvCell).join(',')).join('\n');
    return reply
      .header('Content-Type', 'text/csv; charset=utf-8')
      .header('Content-Disposition', `attachment; filename="webnique-leads-${jobId}.csv"`)
      .send(csv);
  });
}

function csvCell(value: unknown): string {
  const text = value === null || value === undefined ? '' : String(value);
  return `"${text.replace(/"/g, '""')}"`;
}

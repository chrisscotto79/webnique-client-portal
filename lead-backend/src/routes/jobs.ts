import type { FastifyInstance } from 'fastify';
import { one, query } from '../db.js';
import { requireApiKey } from '../auth.js';
import { sweepQueue } from '../queues.js';
import type { CreateJobInput } from '../types.js';

export async function jobRoutes(app: FastifyInstance) {
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
}

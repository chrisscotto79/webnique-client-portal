import Fastify from 'fastify';
import cors from '@fastify/cors';
import { config, requireEnv } from './config.js';
import { jobRoutes } from './routes/jobs.js';

requireEnv();

const app = Fastify({ logger: true });

await app.register(cors, { origin: true });
await app.register(jobRoutes);

app.get('/v1/health', async () => ({ ok: true, service: 'webnique-lead-backend' }));

await app.listen({ port: config.port, host: '0.0.0.0' });

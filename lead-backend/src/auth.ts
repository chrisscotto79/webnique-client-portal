import type { FastifyRequest, FastifyReply } from 'fastify';
import { config } from './config.js';

export async function requireApiKey(req: FastifyRequest, reply: FastifyReply): Promise<void> {
  const header = req.headers.authorization || '';
  const token = header.startsWith('Bearer ') ? header.slice(7) : '';
  if (!config.apiKey || token !== config.apiKey) {
    await reply.code(401).send({ error: 'Unauthorized' });
  }
}

import { Queue } from 'bullmq';
import { redis } from './redis.js';

export const sweepQueue = new Queue('lead-sweep', {
  connection: redis,
  defaultJobOptions: {
    attempts: 2,
    backoff: { type: 'exponential', delay: 10_000 },
    removeOnComplete: 5000,
    removeOnFail: 10000
  }
});

export const zipQueue = new Queue('zip-search', {
  connection: redis,
  defaultJobOptions: {
    attempts: 4,
    backoff: { type: 'exponential', delay: 30_000 },
    removeOnComplete: 10000,
    removeOnFail: 20000
  }
});

export const enrichQueue = new Queue('lead-enrich', {
  connection: redis,
  defaultJobOptions: {
    attempts: 3,
    backoff: { type: 'exponential', delay: 20_000 },
    removeOnComplete: 10000,
    removeOnFail: 20000
  }
});

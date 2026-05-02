import 'dotenv/config';

export const config = {
  port: Number(process.env.PORT || 8080),
  apiKey: process.env.API_KEY || '',
  databaseUrl: process.env.DATABASE_URL || '',
  redisUrl: process.env.REDIS_URL || 'redis://localhost:6379',
  outscraperApiKey: process.env.OUTSCRAPER_API_KEY || '',
  mapsProvider: process.env.MAPS_PROVIDER || 'outscraper',
  maxZipConcurrency: Number(process.env.MAX_ZIP_CONCURRENCY || 4),
  maxEnrichConcurrency: Number(process.env.MAX_ENRICH_CONCURRENCY || 20)
};

export function requireEnv(): void {
  for (const [name, value] of Object.entries({
    API_KEY: config.apiKey,
    DATABASE_URL: config.databaseUrl,
    REDIS_URL: config.redisUrl
  })) {
    if (!value) throw new Error(`${name} is required`);
  }
}

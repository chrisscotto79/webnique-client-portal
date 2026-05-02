import 'dotenv/config';

export const config = {
  port: Number(process.env.PORT || 8080),
  apiKey: process.env.API_KEY || '',
  databaseUrl: process.env.DATABASE_URL || '',
  redisUrl: process.env.REDIS_URL || 'redis://localhost:6379',
  puppeteerHeadless: process.env.PUPPETEER_HEADLESS !== 'false',
  puppeteerExecutablePath: process.env.PUPPETEER_EXECUTABLE_PATH || '',
  mapsResultsLimit: Number(process.env.MAPS_RESULTS_LIMIT || 40),
  mapsScrollRounds: Number(process.env.MAPS_SCROLL_ROUNDS || 30),
  mapsScrollDelayMs: Number(process.env.MAPS_SCROLL_DELAY_MS || 1200),
  maxZipConcurrency: Number(process.env.MAX_ZIP_CONCURRENCY || 2),
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

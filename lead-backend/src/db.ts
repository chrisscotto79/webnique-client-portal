import pg from 'pg';
import { config } from './config.js';

export const db = new pg.Pool({
  connectionString: config.databaseUrl,
  max: 20
});

export async function query<T = any>(sql: string, params: unknown[] = []): Promise<T[]> {
  const result = await db.query(sql, params);
  return result.rows as T[];
}

export async function one<T = any>(sql: string, params: unknown[] = []): Promise<T | null> {
  const rows = await query<T>(sql, params);
  return rows[0] || null;
}

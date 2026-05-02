import { config } from '../config.js';
import type { MapsBusiness } from '../types.js';

type OutscraperRow = Record<string, any>;

export async function searchOutscraper(query: string, maxReviews: number): Promise<MapsBusiness[]> {
  if (!config.outscraperApiKey) {
    throw new Error('OUTSCRAPER_API_KEY is required for Maps discovery');
  }

  const url = new URL('https://api.outscraper.com/maps/search-v3');
  url.searchParams.set('query', query);
  url.searchParams.set('limit', '20');
  url.searchParams.set('language', 'en');
  url.searchParams.set('region', 'US');

  const response = await fetch(url, {
    headers: { 'X-API-KEY': config.outscraperApiKey }
  });

  if (!response.ok) {
    throw new Error(`Outscraper failed with HTTP ${response.status}`);
  }

  const payload = await response.json();
  const rows = flattenOutscraperPayload(payload);

  return rows
    .map(normalizeOutscraperRow)
    .filter((business): business is MapsBusiness => Boolean(business?.name))
    .filter(business => (business.reviewCount || 0) <= maxReviews);
}

function flattenOutscraperPayload(payload: any): OutscraperRow[] {
  if (Array.isArray(payload?.data?.[0])) return payload.data[0];
  if (Array.isArray(payload?.data)) return payload.data.flat();
  if (Array.isArray(payload)) return payload.flat();
  return [];
}

function normalizeOutscraperRow(row: OutscraperRow): MapsBusiness | null {
  const name = row.name || row.title || row.business_name;
  if (!name) return null;

  return {
    sourcePlaceId: row.place_id || row.google_id || row.data_id || row.cid || '',
    name,
    phone: row.phone || row.phone_number || '',
    website: row.site || row.website || '',
    address: row.full_address || row.address || '',
    city: row.city || '',
    state: row.state || row.us_state || '',
    zip: row.postal_code || row.zip || '',
    rating: Number(row.rating || 0),
    reviewCount: Number(row.reviews || row.review_count || 0),
    googleMapsUrl: row.google_maps_url || row.location_link || row.url || '',
    raw: row
  };
}

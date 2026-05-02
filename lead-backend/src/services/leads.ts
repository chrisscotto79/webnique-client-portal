import { one, query } from '../db.js';
import type { MapsBusiness } from '../types.js';

export async function upsertLead(input: {
  jobId: string;
  source: string;
  keyword: string;
  business: MapsBusiness;
}) {
  const b = input.business;
  const score = scoreLead(b);
  const sourcePlaceId = b.sourcePlaceId || b.googleMapsUrl || b.website || `${b.name}|${b.phone || ''}|${b.address || ''}`;

  return one(
    `INSERT INTO leads (
      job_id, source, source_place_id, business_name, industry, phone, website,
      address, city, state, zip, rating, review_count, google_maps_url, lead_score, raw
    ) VALUES (
      $1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16
    )
    ON CONFLICT (source, source_place_id) WHERE source_place_id IS NOT NULL AND source_place_id <> ''
    DO UPDATE SET
      job_id = EXCLUDED.job_id,
      phone = COALESCE(NULLIF(EXCLUDED.phone, ''), leads.phone),
      website = COALESCE(NULLIF(EXCLUDED.website, ''), leads.website),
      review_count = EXCLUDED.review_count,
      updated_at = NOW()
    RETURNING *`,
    [
      input.jobId,
      input.source,
      sourcePlaceId,
      b.name,
      input.keyword,
      b.phone || '',
      b.website || '',
      b.address || '',
      b.city || '',
      b.state || '',
      b.zip || '',
      b.rating || 0,
      b.reviewCount || 0,
      b.googleMapsUrl || '',
      score,
      JSON.stringify(b.raw || {})
    ]
  );
}

export async function updateLeadEnrichment(leadId: string, data: {
  email?: string;
  emailSource?: string;
  phone?: string;
  socials: Record<string, string>;
}) {
  await query(
    `UPDATE leads SET
      email = COALESCE($2, email),
      email_source = COALESCE($3, email_source),
      phone = COALESCE($4, phone),
      facebook = COALESCE($5, facebook),
      instagram = COALESCE($6, instagram),
      linkedin = COALESCE($7, linkedin),
      youtube = COALESCE($8, youtube),
      tiktok = COALESCE($9, tiktok),
      updated_at = NOW()
    WHERE id = $1`,
    [
      leadId,
      data.email || null,
      data.emailSource || null,
      data.phone || null,
      data.socials.facebook || null,
      data.socials.instagram || null,
      data.socials.linkedin || null,
      data.socials.youtube || null,
      data.socials.tiktok || null
    ]
  );
}

function scoreLead(b: MapsBusiness): number {
  let score = 0;
  if ((b.reviewCount || 0) < 10) score += 25;
  if ((b.reviewCount || 0) < 50) score += 15;
  if (!b.website) score += 20;
  if (!b.phone) score += 10;
  if ((b.rating || 5) < 4) score += 10;
  return Math.min(score, 100);
}

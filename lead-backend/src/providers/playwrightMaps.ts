import { createHash } from 'node:crypto';
import { chromium } from 'playwright';
import { config } from '../config.js';
import type { MapsBusiness } from '../types.js';

type BrowserBusiness = Omit<MapsBusiness, 'sourcePlaceId' | 'raw'> & {
  rawText?: string;
};

export async function searchGoogleMapsWithPlaywright(query: string, maxReviews: number): Promise<MapsBusiness[]> {
  const browser = await chromium.launch({
    headless: config.playwrightHeadless,
    args: ['--disable-blink-features=AutomationControlled', '--no-sandbox']
  });

  try {
    const context = await browser.newContext({
      locale: 'en-US',
      viewport: { width: 1365, height: 900 },
      userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
    });
    const page = await context.newPage();
    const url = `https://www.google.com/maps/search/${encodeURIComponent(query)}?hl=en`;

    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(2500);

    const consent = page.getByRole('button', { name: /accept all|reject all|i agree/i }).first();
    if (await consent.isVisible().catch(() => false)) {
      await consent.click({ timeout: 3000 }).catch(() => undefined);
      await page.waitForTimeout(1000);
    }

    await page.waitForSelector('[role="feed"], a[href*="/maps/place/"], [jsaction*="mouseover:pane"]', { timeout: 30000 });
    await scrollResults(page);

    const rows = await page.evaluate(() => {
      const unique = new Map<string, BrowserBusiness>();
      const roots = Array.from(document.querySelectorAll('[role="feed"] [jsaction*="mouseover:pane"], [role="feed"] [role="article"], [jsaction*="mouseover:pane"], [role="article"]'));

      for (const root of roots) {
        const placeLink = root.querySelector<HTMLAnchorElement>('a[href*="/maps/place/"]');
        const googleMapsUrl = placeLink?.href || '';
        const name = root.querySelector<HTMLElement>('.fontHeadlineSmall')?.innerText?.trim()
          || placeLink?.getAttribute('aria-label')?.trim()
          || placeLink?.textContent?.trim()
          || '';

        if (!name || !googleMapsUrl || unique.has(googleMapsUrl)) continue;

        const text = (root.textContent || '').replace(/\s+/g, ' ').trim();
        const ratingNode = root.querySelector<HTMLElement>('[role="img"][aria-label*="stars"], [aria-label*="stars"]');
        const ratingText = ratingNode?.getAttribute('aria-label') || text;
        const rating = Number((ratingText.match(/([0-5](?:\.\d)?)\s*stars?/i) || [])[1] || 0);
        const reviewCount = Number(((ratingText.match(/([\d,]+)\s*reviews?/i) || text.match(/\(([\d,]+)\)/)) || [])[1]?.replace(/,/g, '') || 0);
        const phone = (text.match(/(?:\+1[\s.-]?)?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}/) || [])[0] || '';
        const website = Array.from(root.querySelectorAll<HTMLAnchorElement>('a[href]'))
          .map(anchor => anchor.href)
          .find(href => !href.includes('google.com/maps') && !href.includes('google.com/search') && !href.startsWith('tel:')) || '';

        const parts = text.split('·').map(part => part.trim()).filter(Boolean);
        const address = parts.find(part => /^\d{1,6}\s+/.test(part) && !part.match(/\d+\s*reviews?/i)) || '';

        unique.set(googleMapsUrl, {
          name,
          phone,
          website,
          address,
          rating,
          reviewCount,
          googleMapsUrl,
          rawText: text
        });
      }

      return Array.from(unique.values());
    });

    return rows
      .filter(row => row.name)
      .map(row => ({
        ...row,
        sourcePlaceId: placeIdFromUrl(row.googleMapsUrl) || stableId(row),
        raw: { query, text: row.rawText }
      }))
      .filter(row => (row.reviewCount || 0) <= maxReviews)
      .slice(0, config.mapsResultsLimit);
  } finally {
    await browser.close();
  }
}

async function scrollResults(page: import('playwright').Page): Promise<void> {
  let lastCount = 0;
  let stableRounds = 0;

  for (let i = 0; i < config.mapsScrollRounds; i++) {
    const count = await page.locator('a[href*="/maps/place/"]').count().catch(() => 0);
    if (count >= config.mapsResultsLimit) break;

    if (count === lastCount) stableRounds++;
    else stableRounds = 0;
    if (stableRounds >= 4) break;
    lastCount = count;

    const feed = page.locator('[role="feed"]').first();
    if (await feed.isVisible().catch(() => false)) {
      await feed.evaluate(element => element.scrollBy(0, element.scrollHeight)).catch(() => undefined);
    } else {
      await page.mouse.wheel(0, 2500);
    }

    await page.waitForTimeout(config.mapsScrollDelayMs);
  }
}

function placeIdFromUrl(url?: string): string {
  if (!url) return '';
  const match = url.match(/[?&]cid=([^&]+)/) || url.match(/!1s([^!]+)/) || url.match(/data=!.*?!1s([^!]+)/);
  return match?.[1] ? decodeURIComponent(match[1]) : '';
}

function stableId(row: BrowserBusiness): string {
  return createHash('sha1')
    .update([row.name, row.phone, row.website, row.address, row.googleMapsUrl].filter(Boolean).join('|'))
    .digest('hex');
}

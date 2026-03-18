'use strict';

/**
 * WNQ Lead Finder — Puppeteer scraper server
 *
 * Runs as a persistent local HTTP server so the browser process
 * stays alive between requests (no cold-start cost per ZIP).
 *
 * Endpoints:
 *   GET /health              — liveness probe
 *   GET /search?q=<query>   — Google Maps search → [{name, place_url, rating, review_count, address, phone, website}]
 *   GET /place?url=<url>    — Maps place page details → {phone, website, rating, review_count, address}
 */

const express   = require('express');
const chromium  = require('@sparticuz/chromium');
const puppeteer = require('puppeteer-core');

const PORT = parseInt(process.env.WNQ_SCRAPER_PORT || '3099', 10);
const app  = express();

// ── Browser lifecycle ────────────────────────────────────────────────────────

let browser = null;

async function getBrowser() {
    if (browser && browser.isConnected()) return browser;
    const execPath = await chromium.executablePath();
    browser = await puppeteer.launch({
        args: [
            ...chromium.args,
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
        ],
        executablePath: execPath,
        headless:       true,
        defaultViewport: { width: 1280, height: 900 },
    });
    browser.on('disconnected', () => { browser = null; });
    return browser;
}

async function newPage() {
    const b    = await getBrowser();
    const page = await b.newPage();

    // Block heavy resources — we only need HTML + JSON
    await page.setRequestInterception(true);
    page.on('request', req => {
        const t = req.resourceType();
        if (['image', 'font', 'media'].includes(t)) req.abort();
        else req.continue();
    });

    await page.setUserAgent(
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
        '(KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36'
    );

    // Pre-accept Google consent cookie so we never land on the consent wall
    await page.setCookie({
        name:   'SOCS',
        value:  'CAISNQgDEitib3FfaWRlbnRpdHlmcm9udGVuZHVpX2xvZ2luX3BhZ2VfMjAyMzA4MTMQARoCZW4',
        domain: '.google.com',
        path:   '/',
    });

    return page;
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

// ── Helpers ──────────────────────────────────────────────────────────────────

function fmtPhone(raw) {
    const digits = raw.replace(/\D/g, '');
    const d10    = digits.length === 11 && digits[0] === '1' ? digits.slice(1) : digits;
    if (d10.length === 10) {
        return `(${d10.slice(0,3)}) ${d10.slice(3,6)}-${d10.slice(6)}`;
    }
    return raw.trim();
}

// ── Routes ───────────────────────────────────────────────────────────────────

app.get('/health', (_req, res) => res.json({ ok: true }));

/**
 * GET /search?q=plumbers+34211
 * Returns up to 20 business listings from Google Maps.
 */
app.get('/search', async (req, res) => {
    const query = (req.query.q || '').trim();
    if (!query) return res.json({ error: 'Missing q', results: [] });

    let page;
    try {
        page = await newPage();

        const url = 'https://www.google.com/maps/search/' +
                    encodeURIComponent(query) + '?hl=en';

        await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 25000 });

        // Wait for the results feed to appear
        await page.waitForSelector('[role="feed"]', { timeout: 12000 }).catch(() => {});
        await sleep(1500);

        // Scroll the left panel to load more results (Google Maps lazy-loads)
        await page.evaluate(async () => {
            const feed = document.querySelector('[role="feed"]');
            if (!feed) return;
            for (let i = 0; i < 6; i++) {
                feed.scrollBy(0, 600);
                await new Promise(r => setTimeout(r, 600));
            }
        });
        await sleep(1000);

        // Extract all business listings
        const results = await page.evaluate(() => {
            const items = [];
            const seenUrls  = new Set();
            const seenNames = new Set();

            const BLOCKED = new Set([
                'feedback','report','contribute','about','help','directions',
                'search','nearby','maps','error','login','signin','share',
            ]);

            // Every clickable place result has an <a href="/maps/place/…">
            document.querySelectorAll('a[href*="/maps/place/"]').forEach(link => {
                const href = link.href || '';
                if (!href || seenUrls.has(href)) return;
                seenUrls.add(href);

                // Name is encoded in the URL path — most reliable source
                const m = href.match(/\/maps\/place\/([^/@?&]+)/);
                if (!m) return;
                let name = decodeURIComponent(m[1].replace(/\+/g, ' '))
                    .replace(/,.*$/, '')
                    .trim();

                if (!name || name.length < 3 || BLOCKED.has(name.toLowerCase())) return;
                if (seenNames.has(name.toLowerCase())) return;
                seenNames.add(name.toLowerCase());

                // Walk up to the article card for extra data
                const card = link.closest('[role="article"]') ||
                             link.closest('li') ||
                             link.parentElement;

                let rating = 0, review_count = 0, address = '';

                if (card) {
                    // Rating — try aria-label="4.5 stars" or aria-label="Rated 4.5"
                    const rEl = card.querySelector('[aria-label*="star" i], [aria-label*="rated" i]');
                    if (rEl) {
                        const rm = (rEl.getAttribute('aria-label') || '').match(/([0-9]+\.?[0-9]*)/);
                        if (rm) rating = parseFloat(rm[1]);
                    }
                    // Review count: try multiple formats Google uses:
                    //   "(123)"  ·  "123 reviews"  ·  "4.5 stars 123 reviews"
                    const cardText = card.innerText || card.textContent || '';
                    const countPatterns = [
                        /\(([0-9,]+)\s*(?:reviews?)?\)/i,   // (123) or (123 reviews)
                        /([0-9,]+)\s+reviews?/i,              // 123 reviews
                    ];
                    for (const pat of countPatterns) {
                        const m = cardText.match(pat);
                        if (m) { review_count = parseInt(m[1].replace(/,/g, ''), 10); break; }
                    }

                    // Address: first span whose text looks like a street address
                    card.querySelectorAll('span').forEach(s => {
                        if (address) return;
                        const t = (s.textContent || '').trim();
                        if (/^\d+\s+\w/.test(t) && t.length > 8 && t.length < 100) {
                            address = t;
                        }
                    });
                }

                items.push({
                    name,
                    place_url:    href,
                    rating,
                    review_count,
                    address,
                    phone:   '',
                    website: '',
                });
            });

            return items;
        });

        await page.close();
        res.json({ results });

    } catch (e) {
        if (page) await page.close().catch(() => {});
        if (browser) { await browser.close().catch(() => {}); browser = null; }
        res.json({ error: e.message, results: [] });
    }
});

/**
 * GET /place?url=https://www.google.com/maps/place/…
 * Returns {phone, website, rating, review_count, address} for one place.
 */
app.get('/place', async (req, res) => {
    const placeUrl = (req.query.url || '').trim();
    if (!placeUrl) return res.json({ error: 'Missing url', phone: '', website: '' });

    let page;
    try {
        page = await newPage();

        const target = placeUrl + (placeUrl.includes('?') ? '&' : '?') + 'hl=en';
        await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 20000 });

        // Wait for the place panel sidebar to render
        await page.waitForSelector('[data-item-id]', { timeout: 8000 }).catch(() => {});
        await sleep(1500);

        const details = await page.evaluate(() => {
            // Phone — Google renders a tel: link for click-to-call
            let phone = '';
            const telLink = document.querySelector('a[href^="tel:"]');
            if (telLink) {
                phone = telLink.href.replace('tel:', '').trim();
            }

            // Website — aria-label contains "website" on the button
            let website = '';
            const websiteLink = document.querySelector(
                'a[data-item-id="authority"], ' +
                'a[aria-label*="website" i], ' +
                'a[aria-label*="Website" i], ' +
                'a[data-tooltip*="website" i]'
            );
            if (websiteLink) {
                website = websiteLink.href || '';
                // Strip Google redirect wrappers
                const qm = website.match(/[?&]q=(https?:\/\/[^&]+)/i);
                if (qm) website = decodeURIComponent(qm[1]);
            }

            // Rating
            let rating = 0;
            const ratingEl = document.querySelector('[aria-label*="star" i]');
            if (ratingEl) {
                const rm = (ratingEl.getAttribute('aria-label') || '').match(/([0-9.]+)/);
                if (rm) rating = parseFloat(rm[1]);
            }

            // Review count — try aria-label first, then body text patterns
            let review_count = 0;
            const reviewEl = document.querySelector('[aria-label*="review" i]');
            if (reviewEl) {
                const rm = (reviewEl.getAttribute('aria-label') || '').match(/([0-9,]+)/);
                if (rm) review_count = parseInt(rm[1].replace(/,/g, ''), 10);
            }
            if (!review_count) {
                const bodyText = document.body.innerText || '';
                const countPatterns = [
                    /([0-9,]+)\s+(?:Google\s+)?reviews?/i,
                    /\(([0-9,]+)\s*(?:reviews?)?\)/i,
                ];
                for (const pat of countPatterns) {
                    const m = bodyText.match(pat);
                    if (m) { review_count = parseInt(m[1].replace(/,/g, ''), 10); break; }
                }
            }

            // Address
            let address = '';
            const addrEl = document.querySelector('[data-item-id="address"] .fontBodyMedium, [aria-label*="Address" i]');
            if (addrEl) address = addrEl.textContent.trim();

            return { phone, website, rating, review_count, address };
        });

        // Format the phone number
        if (details.phone) details.phone = fmtPhone(details.phone);

        await page.close();
        res.json(details);

    } catch (e) {
        if (page) await page.close().catch(() => {});
        if (browser) { await browser.close().catch(() => {}); browser = null; }
        res.json({ error: e.message, phone: '', website: '', rating: 0, review_count: 0, address: '' });
    }
});

// ── Start ────────────────────────────────────────────────────────────────────

app.listen(PORT, '127.0.0.1', () => {
    console.log(`WNQ scraper running on 127.0.0.1:${PORT}`);
});

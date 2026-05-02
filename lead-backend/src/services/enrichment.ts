import * as cheerio from 'cheerio';

const EMAIL_RE = /[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/i;
const PHONE_RE = /\(?\b(\d{3})\)?[\s.-](\d{3})[\s.-](\d{4})\b/;

export type EnrichmentResult = {
  email?: string;
  emailSource?: string;
  phone?: string;
  socials: Record<string, string>;
};

export async function enrichWebsite(website: string): Promise<EnrichmentResult> {
  const first = await fetchHtml(website);
  const contactUrl = first.html ? findContactUrl(website, first.html) : '';
  const second = contactUrl ? await fetchHtml(contactUrl) : { html: '', url: '' };
  const combined = `${first.html}\n${second.html}`;

  const email = extractEmail(combined);
  return {
    email: email || undefined,
    emailSource: email ? (second.html && second.html.includes(email) ? second.url : first.url) : undefined,
    phone: extractPhone(combined) || undefined,
    socials: extractSocials(combined)
  };
}

async function fetchHtml(url: string): Promise<{ html: string; url: string }> {
  try {
    const response = await fetch(url, {
      redirect: 'follow',
      signal: AbortSignal.timeout(10_000),
      headers: {
        'user-agent': 'Mozilla/5.0 (compatible; WebNiqueLeadBot/1.0; +https://webnique.com)'
      }
    });
    const contentType = response.headers.get('content-type') || '';
    if (!response.ok || !contentType.includes('text/html')) return { html: '', url };
    const html = await response.text();
    return { html: html.slice(0, 1_000_000), url: response.url || url };
  } catch {
    return { html: '', url };
  }
}

function extractEmail(html: string): string {
  const $ = cheerio.load(html);
  const mailto = $('a[href^="mailto:"]').first().attr('href')?.replace(/^mailto:/i, '').split('?')[0] || '';
  const candidate = mailto || $.root().text().match(EMAIL_RE)?.[0] || '';
  const email = candidate.toLowerCase().trim();
  if (!email || email.includes('example.com') || /\.(png|jpe?g|gif|webp|svg|pdf)$/i.test(email)) return '';
  return email;
}

function extractPhone(html: string): string {
  const $ = cheerio.load(html);
  const tel = $('a[href^="tel:"]').first().attr('href')?.replace(/^tel:/i, '') || '';
  const source = tel || $.root().text();
  const match = source.match(PHONE_RE);
  if (!match) return '';
  return `(${match[1]}) ${match[2]}-${match[3]}`;
}

function extractSocials(html: string): Record<string, string> {
  const socials: Record<string, string> = {};
  const $ = cheerio.load(html);
  const patterns: Record<string, (href: string) => boolean> = {
    facebook: href => /facebook\.com\/(?!sharer)/i.test(href),
    instagram: href => /instagram\.com\//i.test(href),
    linkedin: href => /linkedin\.com\/(?:company|in)\//i.test(href),
    youtube: href => /youtube\.com\/(?:channel\/|c\/|user\/|@)/i.test(href),
    tiktok: href => /tiktok\.com\/@/i.test(href)
  };

  $('a[href]').each((_, element) => {
    const href = $(element).attr('href') || '';
    if (!href.startsWith('http')) return;
    for (const [network, matches] of Object.entries(patterns)) {
      if (!socials[network] && matches(href)) socials[network] = href.replace(/[)"'.,;]+$/, '');
    }
  });

  return socials;
}

function findContactUrl(baseUrl: string, html: string): string {
  const $ = cheerio.load(html);
  const hrefs = $('a[href]').map((_, element) => $(element).attr('href') || '').get();
  const contact = hrefs.find(href => /contact|about|get-in-touch/i.test(href));
  if (!contact) return '';
  try {
    return new URL(contact, baseUrl).toString();
  } catch {
    return '';
  }
}

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
  const mailto = html.match(/mailto:([^"'? >]+)/i)?.[1] || '';
  const candidate = mailto || html.replace(/<[^>]+>/g, ' ').match(EMAIL_RE)?.[0] || '';
  const email = candidate.toLowerCase().trim();
  if (!email || email.includes('example.com') || /\.(png|jpe?g|gif|webp|svg|pdf)$/i.test(email)) return '';
  return email;
}

function extractPhone(html: string): string {
  const tel = html.match(/href=["']tel:([^"']+)/i)?.[1] || '';
  const source = tel || html.replace(/<[^>]+>/g, ' ');
  const match = source.match(PHONE_RE);
  if (!match) return '';
  return `(${match[1]}) ${match[2]}-${match[3]}`;
}

function extractSocials(html: string): Record<string, string> {
  const socials: Record<string, string> = {};
  const patterns: Record<string, RegExp> = {
    facebook: /https?:\/\/(?:www\.)?facebook\.com\/(?!sharer)[a-zA-Z0-9._/-]+/i,
    instagram: /https?:\/\/(?:www\.)?instagram\.com\/[a-zA-Z0-9._-]+/i,
    linkedin: /https?:\/\/(?:www\.)?linkedin\.com\/(?:company|in)\/[a-zA-Z0-9._-]+/i,
    youtube: /https?:\/\/(?:www\.)?youtube\.com\/(?:channel\/|c\/|user\/|@)[a-zA-Z0-9._-]+/i,
    tiktok: /https?:\/\/(?:www\.)?tiktok\.com\/@[a-zA-Z0-9._-]+/i
  };

  for (const [network, pattern] of Object.entries(patterns)) {
    const found = html.match(pattern)?.[0] || '';
    if (found) socials[network] = found.replace(/[)"'.,;]+$/, '');
  }

  return socials;
}

function findContactUrl(baseUrl: string, html: string): string {
  const hrefs = Array.from(html.matchAll(/href=["']([^"']+)["']/gi)).map(match => match[1]);
  const contact = hrefs.find(href => /contact|about|get-in-touch/i.test(href));
  if (!contact) return '';
  try {
    return new URL(contact, baseUrl).toString();
  } catch {
    return '';
  }
}

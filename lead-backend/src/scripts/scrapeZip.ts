import 'dotenv/config';
import { searchGoogleMapsWithPuppeteer } from '../providers/puppeteerMaps.js';
import { enrichWebsite } from '../services/enrichment.js';

type Args = {
  keyword: string;
  zip: string;
  maxReviews: number;
  enrich: boolean;
};

const args = parseArgs(process.argv.slice(2));

if (!args.keyword || !args.zip) {
  console.error('Usage: npm run scrape:zip -- --keyword "plumbing" --zip 32825 [--max-reviews 50] [--no-enrich]');
  process.exit(1);
}

const businesses = await searchGoogleMapsWithPuppeteer(`${args.keyword} in ${args.zip}`, args.maxReviews);
const leads = [];

for (const business of businesses) {
  const enrichment = args.enrich && business.website
    ? await enrichWebsite(business.website)
    : { socials: {} };

  leads.push({
    ...business,
    email: enrichment.email || '',
    emailSource: enrichment.emailSource || '',
    phone: business.phone || enrichment.phone || '',
    socials: enrichment.socials
  });
}

console.log(JSON.stringify({
  keyword: args.keyword,
  zip: args.zip,
  found: leads.length,
  leads
}, null, 2));

function parseArgs(argv: string[]): Args {
  const parsed: Args = {
    keyword: '',
    zip: '',
    maxReviews: 50,
    enrich: true
  };

  for (let i = 0; i < argv.length; i++) {
    const value = argv[i];
    if (value === '--keyword') parsed.keyword = argv[++i] || '';
    else if (value === '--zip') parsed.zip = argv[++i] || '';
    else if (value === '--max-reviews') parsed.maxReviews = Number(argv[++i] || 50);
    else if (value === '--no-enrich') parsed.enrich = false;
  }

  return parsed;
}

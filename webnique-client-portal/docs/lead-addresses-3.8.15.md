# 3.8.15 — visible lead location and 250 ZIP batches

Lead List now displays address, city, state and actual listing ZIP directly under each business, alongside existing website/phone/email and expanded contact/source information. Missing locations have explicit labels. City parsing supports city-only US addresses, absent ZIPs, ZIP+4 and USA/United States suffixes. Existing stored addresses are parsed for display when location columns are blank; this does not overwrite stored records. No search ZIP is substituted for a business address.

Bulk ZIP validation accepts 250 unique five-digit codes; 251 are rejected. Existing three-tab cap and 100 listings per ZIP remain unchanged. Larger batches take longer, not more tabs.

Address extraction from Maps and GHL creation address mapping already existed and remain in use. This release does not invent hidden service-business addresses, rescrape historical leads or overwrite existing GHL contacts. Previously missing raw addresses still require recollection; already sent contacts are not re-enrolled.

Tests: 18 history tests including 250/251 boundaries, 34 intake/email/security tests including location variants, lead-list PHP and desktop/mobile UI tests, PHP syntax and diff checks. No live GHL writes. Plugin-only update, no companion extension changes.

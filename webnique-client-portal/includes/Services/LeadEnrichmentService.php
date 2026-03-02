<?php
/**
 * Lead Enrichment Service
 *
 * Handles deep enrichment of a discovered lead:
 *   - Owner first/last name extraction (schema markup + HTML heuristics)
 *   - Social media link extraction (Facebook, Instagram, LinkedIn, Twitter/X, YouTube, TikTok)
 *   - Franchise / chain detection (skip these — they don't need our SEO services)
 *   - Address parsing (splits Google formatted_address into street, city, state, zip)
 *
 * All HTML fetching reuses already-retrieved HTML where passed in, to minimise
 * duplicate HTTP requests per lead.
 *
 * @package WebNique Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class LeadEnrichmentService
{
    // ── Franchise / Chain Detection ──────────────────────────────────────────

    /**
     * Known franchise brands and chain names.
     * Matched as case-insensitive substring against the business name.
     * Keep alphabetical within each category.
     */
    private const FRANCHISE_NAMES = [
        // Home Services – HVAC / Plumbing / Electrical
        '1-800-plumber', 'abc plumbing', 'aire serv', 'american leak detection',
        'ars rescue rooter', 'ben franklin plumbing', 'benjamin franklin plumbing',
        'bluefrog plumbing', 'clockwork air', 'comfort systems',
        'cool today', 'days mechanical', 'ductz', 'enviroair',
        'firestop', 'groundworks', 'john c. flood', 'mister sparky',
        'mr. electric', 'mr. rooter', 'one hour air', 'one hour heating',
        'rescue rooter', 'roto-rooter', 'rooter', 'service experts',
        'servicemaster', 'sewer squad', 'trane comfort', 'wrench group',

        // Pest Control
        'arrow exterminators', 'bulwark exterminating', 'ehrlich pest',
        'massey services', 'orkin', 'rentokil', 'rollins', 'terminix',
        'trugreen', 'weed man', 'western exterminator',

        // Cleaning / Restoration
        'all dry', 'all pro restoration', 'belfor', 'bio-one',
        'chem-dry', 'classiclean', 'coverall', 'details cleaning',
        'jan-pro', 'janiking', 'maidpro', 'merry maids', 'molly maid',
        'paul davis', 'rainbow international', 'servpro', 'stanley steemer',
        'the cleaning authority', 'two maids', 'zerorez',

        // Landscaping / Lawn
        'brightview', 'davey tree', 'lawn doctor', 'lawnstarter',
        'living earth crafts', 'naturalawn', 'plot twist', 'spring-green',
        'sunnyside landscaping', 'trugreen',

        // Painting
        "five star painting", "fresh coat painters", "imagepro painters",
        'painting with a twist', 'pro painters', 'richcoat painters',

        // Flooring / Windows / Doors
        'california closets', 'closet factory', 'closet world',
        'floor & decor', 'flooring america', 'luna flooring',
        'pella windows', 'renewal by andersen', 'window nation',
        'window world',

        // Auto Services
        'aamco', 'abra auto', 'advance auto', 'autozone',
        'caliber collision', 'car-x', 'christian brothers auto',
        'cottman transmission', 'express oil change', 'firestone',
        'gerber collision', 'grease monkey', 'jiffy lube', 'jiffy', 'kelley blue book',
        'maaco', 'mavis', 'meineke', 'midas', 'monro muffler',
        'napa auto', "o'reilly", "pep boys", 'safelite',
        'service king', 'sears auto', 'take 5 oil', 'valvoline',

        // Retail / Hardware
        'ace hardware', 'advance auto parts', 'autozone',
        'benjamin moore', 'color tile', 'do it best',
        'sherwin-williams', 'true value',

        // Dental / Vision / Medical
        'afc urgent care', 'aspen dental', 'atl urgent care',
        'bright now dental', 'concentra', 'dental care alliance',
        'euromarket designs', 'fastmed', 'heartland dental',
        'lenscrafters', 'luxottica', 'medexpress', 'myeyedr',
        'national vision', 'nextcare urgent', 'pacific dental',
        'perfect smile dental', 'smile brands', 'smile direct',
        'solstice dental', 'total vision', 'visionworks', 'western dental',

        // Fitness
        'anytime fitness', 'crunch fitness', 'cycling house',
        'f45 training', 'gold\'s gym', 'la fitness',
        'lifetime fitness', 'orangetheory', 'planet fitness',
        'pure barre', 'snap fitness', 'title boxing',

        // Real Estate
        'berkshire hathaway', 'better homes and gardens realty',
        'century 21', 'coldwell banker', 'era real estate',
        'exp realty', 'howard hanna', 'keller williams', 'redfin',
        're/max', 'realty one', 'sotheby\'s realty',
        'united real estate', 'weichert',

        // Insurance
        'allstate', 'american family insurance', "farmer's insurance",
        "farmers insurance", 'liberty mutual', 'nationwide insurance',
        'progressive insurance', 'state farm',

        // Financial
        'ameritas', 'edward jones', 'h&r block', 'jackson hewitt',
        'liberty tax', 'northwestern mutual', 'primerica', 'regions bank',

        // Restaurants / Food (commonly searched but not target market)
        "applebee's", "arby's", 'auntie anne\'s', 'baskin-robbins',
        'ben & jerry\'s', 'bob evans', 'bojangles',
        'buffalo wild wings', 'burger king', 'carl\'s jr', 'chick-fil-a',
        "chili's", 'chipotle', 'church\'s chicken', 'cinnabon',
        'coldstone creamery', 'culver\'s', 'dairy queen', 'del taco',
        "denny's", 'domino\'s', 'dunkin', 'el pollo loco',
        'fatburger', 'firehouse subs', 'five guys', "freddy's",
        'golden corral', 'hardee\'s', "hooter's", 'iHop',
        "jack in the box", "jamba juice", "jersey mike's",
        "jimmy john's", 'kfc', 'krispy kreme', 'little caesars',
        'long john silver\'s', 'marco\'s pizza', 'mcdonald\'s',
        'mcdonalds', 'moe\'s southwest', "panera bread", "papa john's",
        "papa murphy's", 'pizookie', 'popeyes', 'pizza hut',
        "qdoba", "quiznos", "raising cane's", "rally's",
        'red lobster', "ruby tuesday", 'shake shack', 'smoothie king',
        'sonic drive', 'starbucks', 'subway', 'taco bell',
        "taco john's", 'tim hortons', 'tropical smoothie',
        "tuesday morning", "waffle house", "wawa", "wendy's",
        "whataburger", "white castle", "wingstop", "zaxby's",
    ];

    /**
     * Return true if this business name matches a known franchise/chain.
     * Also checks website HTML if provided (looks for "franchise" disclosures).
     */
    public static function isFranchise(string $business_name, string $homepage_html = ''): bool
    {
        $name_lower = strtolower($business_name);
        foreach (self::FRANCHISE_NAMES as $keyword) {
            if (str_contains($name_lower, strtolower($keyword))) {
                return true;
            }
        }

        // Check website for franchise disclosure text
        if ($homepage_html) {
            $text_lower = strtolower(strip_tags($homepage_html));
            if (preg_match('/\bfranchis(e|or|ee|ed|ing)\b/', $text_lower)) {
                // Extra confirmation: also contains the brand word prominently
                if (str_contains($text_lower, 'independently owned and operated')) {
                    return true;
                }
            }
        }

        return false;
    }

    // ── Address Parsing ──────────────────────────────────────────────────────

    /**
     * Parse a Google formatted_address string into components.
     * Input: "123 Main St, Orlando, FL 32801, USA"
     * Output: ['street' => '123 Main St', 'city' => 'Orlando', 'state' => 'FL', 'zip' => '32801']
     */
    public static function parseAddress(string $formatted_address): array
    {
        $result = ['street' => '', 'city' => '', 'state' => '', 'zip' => ''];
        if (!$formatted_address) return $result;

        // Remove country suffix
        $address = preg_replace('/,?\s*(USA|United States|US|Canada|CA)$/i', '', trim($formatted_address));

        // Extract state + zip from the last comma-delimited segment
        // e.g. "FL 32801" or "Florida 32801-4567" or just "FL"
        if (preg_match('/,\s*([A-Za-z]{2,})\s+(\d{5}(?:-\d{4})?)(?:\s*,|$)/', $address, $m)) {
            $result['state'] = strtoupper(trim($m[1]));
            $result['zip']   = trim($m[2]);
            // Remove from string so we can find city
            $address = str_replace($m[0], '', $address);
        } elseif (preg_match('/,\s*([A-Z]{2})\s*$/i', $address, $m)) {
            $result['state'] = strtoupper(trim($m[1]));
            $address = substr($address, 0, -strlen($m[0]));
        }

        // Remaining parts: last is city, everything before is street
        $parts = array_map('trim', explode(',', trim($address, ', ')));
        $parts = array_filter($parts);

        if (count($parts) >= 2) {
            $result['city']   = array_pop($parts);
            $result['street'] = implode(', ', $parts);
        } elseif (count($parts) === 1) {
            $result['city'] = $parts[0];
        }

        return $result;
    }

    // ── Owner Name Extraction ────────────────────────────────────────────────

    /**
     * Attempt to extract the business owner's first and last name.
     * Strategy:
     *   1. Parse JSON-LD schema markup for Person entities (founder/owner/employee)
     *   2. Search About/Contact page text for common owner-name patterns
     *
     * @param  string $base_url      Root URL of the business website
     * @param  string $homepage_html Already-fetched homepage HTML (avoids refetch)
     * @return array{first: string, last: string}
     */
    public static function extractOwnerName(string $base_url, string $homepage_html = ''): array
    {
        $empty = ['first' => '', 'last' => ''];

        // 1. Try schema markup on homepage
        if ($homepage_html) {
            $result = self::ownerFromSchema($homepage_html);
            if ($result['first']) return $result;

            $result = self::ownerFromHtmlText($homepage_html);
            if ($result['first']) return $result;
        }

        // 2. Fetch About page
        $about_html = self::fetchHtml(rtrim($base_url, '/') . '/about');
        if ($about_html) {
            $result = self::ownerFromSchema($about_html);
            if ($result['first']) return $result;

            $result = self::ownerFromHtmlText($about_html);
            if ($result['first']) return $result;
        }

        // 3. Fetch Contact page
        $contact_html = self::fetchHtml(rtrim($base_url, '/') . '/contact');
        if ($contact_html) {
            $result = self::ownerFromSchema($contact_html);
            if ($result['first']) return $result;

            $result = self::ownerFromHtmlText($contact_html);
            if ($result['first']) return $result;
        }

        return $empty;
    }

    // ── Social Media Extraction ──────────────────────────────────────────────

    /**
     * Extract social media profile URLs from the website homepage.
     *
     * @param  string $base_url      Root URL of the business website
     * @param  string $homepage_html Already-fetched homepage HTML (avoids refetch)
     * @return array{facebook: string, instagram: string, linkedin: string, twitter: string, youtube: string, tiktok: string}
     */
    public static function extractSocialMedia(string $base_url, string $homepage_html = ''): array
    {
        $social = [
            'facebook'  => '',
            'instagram' => '',
            'linkedin'  => '',
            'twitter'   => '',
            'youtube'   => '',
            'tiktok'    => '',
        ];

        $html = $homepage_html ?: self::fetchHtml($base_url);
        if (!$html) return $social;

        // Extract all href attributes
        preg_match_all('/href=["\']([^"\']+)["\']/', $html, $matches);
        $hrefs = $matches[1] ?? [];

        foreach ($hrefs as $href) {
            $href = trim($href);
            if (!$href || str_starts_with($href, '#') || str_starts_with($href, 'mailto:')) continue;

            // Normalise: add scheme if relative
            if (str_starts_with($href, '//')) $href = 'https:' . $href;

            $href_lower = strtolower($href);

            if (!$social['facebook']  && str_contains($href_lower, 'facebook.com/') && !str_contains($href_lower, 'sharer')) {
                $social['facebook'] = $href;
            } elseif (!$social['instagram'] && str_contains($href_lower, 'instagram.com/')) {
                $social['instagram'] = $href;
            } elseif (!$social['linkedin']  && str_contains($href_lower, 'linkedin.com/')) {
                $social['linkedin'] = $href;
            } elseif (!$social['twitter']   && (str_contains($href_lower, 'twitter.com/') || str_contains($href_lower, 'x.com/'))) {
                $social['twitter'] = $href;
            } elseif (!$social['youtube']   && str_contains($href_lower, 'youtube.com/')) {
                $social['youtube'] = $href;
            } elseif (!$social['tiktok']    && str_contains($href_lower, 'tiktok.com/')) {
                $social['tiktok'] = $href;
            }
        }

        return $social;
    }

    // ── Private Helpers ──────────────────────────────────────────────────────

    private static function fetchHtml(string $url): string
    {
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) return '';

        $response = wp_remote_get($url, [
            'timeout'             => 4,
            'user-agent'          => 'Mozilla/5.0 (compatible; WebNique/1.0; +https://webnique.com)',
            'sslverify'           => false,
            'redirection'         => 2,
            'limit_response_size' => 256000,
        ]);

        if (is_wp_error($response)) return '';
        $code = (int)wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 400) return '';

        return wp_remote_retrieve_body($response);
    }

    /**
     * Parse JSON-LD schema blocks to find a Person with an ownership-related role.
     */
    private static function ownerFromSchema(string $html): array
    {
        $empty = ['first' => '', 'last' => ''];

        preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches);
        foreach ($matches[1] as $json_raw) {
            $data = json_decode(trim($json_raw), true);
            if (!$data) continue;

            // Flatten top-level + @graph arrays
            $nodes = isset($data['@graph']) ? $data['@graph'] : [$data];

            foreach ($nodes as $node) {
                $name = self::personNameFromNode($node);
                if ($name) return self::splitName($name);

                // Check nested arrays: founder, employee, member, contactPoint
                foreach (['founder', 'employee', 'member', 'author', 'contactPoint', 'owns'] as $key) {
                    if (empty($node[$key])) continue;
                    $entries = isset($node[$key]['@type']) ? [$node[$key]] : (array)$node[$key];
                    foreach ($entries as $entry) {
                        $name = self::personNameFromNode($entry);
                        if ($name) return self::splitName($name);
                    }
                }
            }
        }

        return $empty;
    }

    private static function personNameFromNode(array $node): string
    {
        $type = $node['@type'] ?? '';
        if (!str_contains(strtolower((string)$type), 'person')) return '';

        $job_title = strtolower($node['jobTitle'] ?? '');
        $owner_titles = ['owner', 'founder', 'president', 'ceo', 'co-owner', 'principal', 'director', 'proprietor'];
        $is_owner = empty($job_title) || array_reduce($owner_titles, fn($carry, $t) => $carry || str_contains($job_title, $t), false);

        if ($is_owner && !empty($node['name'])) {
            return (string)$node['name'];
        }

        return '';
    }

    /**
     * Regex-based owner name extraction from visible page text.
     * Looks for patterns like "John Smith, Owner" or "Founded by John Smith".
     */
    private static function ownerFromHtmlText(string $html): array
    {
        $empty = ['first' => '', 'last' => ''];

        // Work with text only
        $text = preg_replace('/\s+/', ' ', strip_tags($html));

        // Name pattern: two capitalised words (handles hyphenated last names too)
        $name_re = '[A-Z][a-z]{1,20}(?:-[A-Z][a-z]{1,20})?\s+[A-Z][a-z]{1,20}(?:-[A-Z][a-z]{1,20})?';

        $patterns = [
            // "Owner: John Smith" or "Founder: John Smith"
            '/(?:owner|founder|president|ceo|principal|proprietor|co-owner)\s*[:–—]\s*(' . $name_re . ')/i',
            // "John Smith, Owner" or "John Smith – Founder"
            '/(' . $name_re . ')\s*[,\-–—]\s*(?:owner|founder|president|ceo|principal|proprietor)/i',
            // "Hi, I'm John Smith" / "I'm John Smith"
            "/i'?m\s+(" . $name_re . ")/i",
            // "My name is John Smith"
            '/my name is\s+(' . $name_re . ')/i',
            // "Meet John Smith"
            '/meet\s+(' . $name_re . ')/i',
            // "Founded by John Smith" / "Owned by John Smith" / "Run by John Smith"
            '/(?:founded|owned|operated|run|managed)\s+by\s+(' . $name_re . ')/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $name = trim($m[1]);
                // Sanity check: reject generic words
                if (!self::isLikelyPersonName($name)) continue;
                return self::splitName($name);
            }
        }

        return $empty;
    }

    private static function isLikelyPersonName(string $name): bool
    {
        $blacklist = ['All Rights', 'Learn More', 'Free Quote', 'Contact Us', 'About Us',
                      'Our Team', 'Read More', 'Get Started', 'Click Here', 'Our Services'];
        foreach ($blacklist as $bad) {
            if (stripos($name, $bad) !== false) return false;
        }
        return strlen($name) >= 5 && strlen($name) <= 45;
    }

    private static function splitName(string $full_name): array
    {
        $parts = explode(' ', trim($full_name), 2);
        return [
            'first' => $parts[0] ?? '',
            'last'  => $parts[1] ?? '',
        ];
    }
}

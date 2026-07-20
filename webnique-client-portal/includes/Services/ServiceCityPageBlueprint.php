<?php
/**
 * Deterministic composer for the approved Service + City Elementor template.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class ServiceCityPageBlueprint
{
    private const SECTION_ORDER = [
        '60181fdf', // Hero.
        '69228ce4', // Trust badges.
        '180e7e80', // Local introduction.
        '89a00ae',  // Service details.
        '7e91beaa', // Why choose us.
        '1d932af2', // Gallery.
        '489da4cf', // Reviews.
        '46f79439', // Service area.
        '24051db0', // FAQ.
        'contact',  // Contact form generated below.
        '5f026f7c', // Final CTA.
    ];

    private const REQUIRED_SECTIONS = [
        '60181fdf',
        '69228ce4',
        '180e7e80',
        '89a00ae',
        '7e91beaa',
        '1d932af2',
        '489da4cf',
        '46f79439',
        '24051db0',
        '5f026f7c',
    ];

    private const TRUST_WIDGETS = ['74c7e36e', '4010d6e8', '2be58b11', '71a36b1b'];

    private const LOCATION_WIDGETS = [
        '43fa312e',
        '5df5c85e',
        '24707265',
        '2b93016',
        '1cdd0ce6',
        '59802bbe',
        '14fa6527',
        '1b72658a',
        'fa55d3d',
        '2d7cc19b',
    ];

    /**
     * Bring older Service + City CSV exports up to the current import contract.
     *
     * This only upgrades rows that already prove they contain both a service and
     * a city. Genuine city-only rows continue to fail validation.
     */
    public static function normalizeImportRow(array $row): array
    {
        $has_service_city_intent = self::hasServiceCityIntent($row);
        $page_type = self::normalizePageType((string)($row['page_type'] ?? ''));
        if ($page_type === 'page' && $has_service_city_intent) {
            $row['page_type'] = 'service_city';
        }

        // Legacy exports often use city paths or service-only path segments. Build
        // the unique child-page slug before duplicate detection collapses rows.
        if ($has_service_city_intent) {
            $row['slug'] = self::canonicalServiceCitySlug($row);
        }

        $navigation_links = trim((string)($row['navigation_menu_related_services'] ?? ''));
        if ($navigation_links === '' || strtolower($navigation_links) === 'navigation_menu_related_services') {
            $derived_links = self::deriveRelatedServiceLinks($row);
            $row['navigation_menu_related_services'] = empty($derived_links)
                ? ''
                : implode(';', $derived_links);
        }

        return $row;
    }

    private static function canonicalServiceCitySlug(array $row): string
    {
        return implode('-', array_filter([
            self::slugify((string)($row['service'] ?? '')),
            self::slugify((string)($row['city'] ?? '')),
            self::slugify((string)($row['state'] ?? '')),
        ], static function (string $part): bool {
            return $part !== '';
        }));
    }

    public static function validateRow(array $row): array
    {
        $errors = [];
        $required = ['service', 'city', 'state', 'h1', 'parent_service_slug', 'page_type'];

        foreach ($required as $field) {
            if (trim((string)($row[$field] ?? '')) === '') {
                $errors[] = $field . ' is required.';
            }
        }

        $page_type = self::normalizePageType((string)($row['page_type'] ?? ''));
        if ($page_type !== '' && !in_array($page_type, ['service_city', 'service_city_page'], true)) {
            $errors[] = 'page_type must be service_city or service_city_page; city-only rows are not supported.';
        }

        $h1 = trim((string)($row['h1'] ?? ''));
        $service = trim((string)($row['service'] ?? ''));
        $city = trim((string)($row['city'] ?? ''));
        if ($h1 !== '' && $service !== '' && stripos($h1, $service) === false) {
            $errors[] = 'h1 must include the current service.';
        }
        if ($h1 !== '' && $city !== '' && stripos($h1, $city) === false) {
            $errors[] = 'h1 must include the current city.';
        }

        self::validatePairedLists(
            $row,
            'related_services',
            'navigation_menu_related_services',
            $errors
        );
        self::validatePairedLists(
            $row,
            'nearby_cities',
            'nav_menu_nearby_areas',
            $errors
        );

        return [
            'valid'  => empty($errors),
            'errors' => $errors,
        ];
    }

    public static function compose(
        string $template,
        array $row,
        string $business_name,
        array $profile = [],
        array $client = []
    ): array {
        $decoded = json_decode($template, true);
        if (!is_array($decoded)) {
            return ['recognized' => false, 'success' => false, 'message' => 'Template JSON is invalid.'];
        }

        $content = isset($decoded['content']) && is_array($decoded['content'])
            ? $decoded['content']
            : $decoded;
        if (!is_array($content)) {
            return ['recognized' => false, 'success' => false, 'message' => 'Template content is missing.'];
        }

        $sections = [];
        foreach ($content as $section) {
            if (is_array($section) && isset($section['id'])) {
                $sections[(string)$section['id']] = $section;
            }
        }

        $recognized_count = count(array_intersect(array_keys($sections), self::REQUIRED_SECTIONS));
        if ($recognized_count < 4) {
            return ['recognized' => false, 'success' => false, 'message' => 'Template is not the approved Service + City blueprint.'];
        }

        $missing = array_values(array_diff(self::REQUIRED_SECTIONS, array_keys($sections)));
        if (!empty($missing)) {
            return [
                'recognized' => true,
                'success'    => false,
                'message'    => 'The Service + City template is missing required section IDs: ' . implode(', ', $missing) . '.',
            ];
        }

        $validation = self::validateRow($row);
        if (!$validation['valid']) {
            return [
                'recognized' => true,
                'success'    => false,
                'message'    => implode(' ', $validation['errors']),
            ];
        }

        $context = self::context($row, $business_name, $profile, $client);
        self::composeHero($sections['60181fdf'], $context);
        self::composeTrust($sections['69228ce4'], $context);
        self::composeIntroduction($sections['180e7e80'], $context);
        self::composeServiceDetails($sections['89a00ae'], $context);
        self::composeWhyChoose($sections['7e91beaa'], $context);
        self::composeGallery($sections['1d932af2'], $context);
        self::composeReviews($sections['489da4cf'], $context);
        self::composeServiceArea($sections['46f79439'], $context);
        self::composeFaq($sections['24051db0'], $context);
        self::composeFinalCta($sections['5f026f7c'], $context);

        $contact = self::contactSection($sections['24051db0'], $context);
        $ordered = [];
        foreach (self::SECTION_ORDER as $section_id) {
            $ordered[] = $section_id === 'contact' ? $contact : $sections[$section_id];
        }

        $page_settings = isset($decoded['page_settings']) && is_array($decoded['page_settings'])
            ? $decoded['page_settings']
            : [];
        $page_settings['hide_title'] = 'yes';

        $body = self::summaryBody($context);
        $encoded = json_encode($ordered, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            return ['recognized' => true, 'success' => false, 'message' => 'Elementor data could not be encoded.'];
        }

        return [
            'recognized' => true,
            'success'    => true,
            'body'       => $body,
            'built'      => [
                'elementor_data' => $encoded,
                'page_settings'  => $page_settings,
                'post_content'   => $body,
            ],
        ];
    }

    private static function validatePairedLists(array $row, string $labels_key, string $links_key, array &$errors): void
    {
        $labels = self::splitList((string)($row[$labels_key] ?? ''));
        $links = self::splitList((string)($row[$links_key] ?? ''));
        if (count($labels) !== count($links)) {
            $errors[] = sprintf(
                '%s and %s must contain the same number of items (%d labels, %d links).',
                $labels_key,
                $links_key,
                count($labels),
                count($links)
            );
        }
    }

    private static function normalizePageType(string $page_type): string
    {
        $page_type = strtolower(trim($page_type));
        return trim((string)preg_replace('/[^a-z0-9]+/', '_', $page_type), '_');
    }

    private static function hasServiceCityIntent(array $row): bool
    {
        foreach (['service', 'city', 'state', 'h1', 'parent_service_slug'] as $key) {
            if (trim((string)($row[$key] ?? '')) === '') {
                return false;
            }
        }

        $h1 = (string)$row['h1'];
        return stripos($h1, (string)$row['service']) !== false
            && stripos($h1, (string)$row['city']) !== false;
    }

    private static function deriveRelatedServiceLinks(array $row): array
    {
        $labels = self::splitList((string)($row['related_services'] ?? ''));
        $internal_links = self::splitList((string)($row['internal_links'] ?? ''));
        if (empty($labels) || empty($internal_links)) {
            return [];
        }

        $links_by_slug = [];
        foreach ($internal_links as $link) {
            $path_slug = self::urlPathSlug($link);
            if ($path_slug !== '') {
                $links_by_slug[$path_slug] = $link;
            }
        }

        $city_slug = self::slugify((string)($row['city'] ?? ''));
        $state_slug = self::slugify((string)($row['state'] ?? ''));
        $city_service_area_slug = trim($city_slug . '-' . $state_slug, '-');
        $derived = [];

        foreach ($labels as $label) {
            $label_slug = self::slugify($label);
            if ($label_slug !== '' && isset($links_by_slug[$label_slug])) {
                $derived[] = $links_by_slug[$label_slug];
                continue;
            }

            if ($label_slug === 'junk-removal' && $city_service_area_slug !== '') {
                $city_root = self::findServiceAreaRoot($internal_links, $city_service_area_slug);
                if ($city_root !== '') {
                    $derived[] = $city_root;
                    continue;
                }
            }

            return [];
        }

        return $derived;
    }

    private static function findServiceAreaRoot(array $links, string $city_service_area_slug): string
    {
        foreach ($links as $link) {
            $path = (string)(parse_url($link, PHP_URL_PATH) ?? '');
            $parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
            $count = count($parts);
            if ($count >= 2
                && self::slugify($parts[$count - 2]) === 'service-areas'
                && self::slugify($parts[$count - 1]) === $city_service_area_slug
            ) {
                return $link;
            }
        }

        return '';
    }

    private static function urlPathSlug(string $url): string
    {
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $parts = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
        return empty($parts) ? '' : self::slugify((string)end($parts));
    }

    private static function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        return trim((string)preg_replace('/[^a-z0-9]+/', '-', $value), '-');
    }

    private static function context(array $row, string $business_name, array $profile, array $client): array
    {
        $service = trim((string)$row['service']);
        $city = trim((string)$row['city']);
        $state = trim((string)$row['state']);
        $county = trim((string)($row['county'] ?? ''));
        $related = self::splitList((string)($row['related_services'] ?? ''));
        $related_links = self::splitList((string)($row['navigation_menu_related_services'] ?? ''));
        $nearby = self::splitList((string)($row['nearby_cities'] ?? ''));
        $nearby_links = self::splitList((string)($row['nav_menu_nearby_areas'] ?? ''));
        $email = trim((string)($profile['email'] ?? $client['email'] ?? ''));
        if ($email === '' && function_exists('get_option')) {
            $email = trim((string)get_option('admin_email', ''));
        }

        return [
            'row'             => $row,
            'business'        => trim($business_name),
            'phone'           => trim((string)($profile['phone'] ?? $client['phone'] ?? '')),
            'email'           => $email,
            'service'         => $service,
            'city'            => $city,
            'state'           => $state,
            'county'          => $county,
            'location'        => trim($city . ', ' . $state, ' ,'),
            'h1'              => trim((string)$row['h1']),
            'primary_keyword' => trim((string)($row['primary_keyword'] ?? $service . ' ' . $city)),
            'meta'            => trim((string)($row['meta_description'] ?? '')),
            'cta_title'       => trim((string)($row['cta_title'] ?? '')),
            'cta_text'        => trim((string)($row['cta_text'] ?? '')),
            'variations'      => self::splitList((string)($row['service_variations'] ?? '')),
            'related'         => $related,
            'related_links'   => $related_links,
            'nearby'          => $nearby,
            'nearby_links'    => $nearby_links,
            'geo'             => self::splitList((string)($row['geo_modifiers'] ?? '')),
            'internal_links'  => self::splitList((string)($row['internal_links'] ?? '')),
        ];
    }

    private static function composeHero(array &$section, array $context): void
    {
        self::setSetting($section, '74bd9f3d', 'title', $context['h1']);
        self::setSetting($section, '4f2a08bf', 'text', $context['primary_keyword']);
        self::setSetting(
            $section,
            '4401b66',
            'editor',
            '<p>' . self::e($context['meta'] ?: self::localIntroSentence($context)) . '</p>'
        );
    }

    private static function composeTrust(array &$section, array $context): void
    {
        self::setSetting($section, '7f3e58f3', 'text', 'Why Choose Us');
        self::setSetting($section, '1cfa4d27', 'title', $context['service'] . ' Made Simple');
        $items = self::trustItems();
        foreach (self::TRUST_WIDGETS as $index => $widget_id) {
            self::setSetting($section, $widget_id, 'title_text', $items[$index][0]);
            self::setSetting($section, $widget_id, 'description_text', $items[$index][1]);
        }
    }

    private static function composeIntroduction(array &$section, array $context): void
    {
        self::setSetting($section, '6c00c886', 'text', $context['service'] . ' ' . $context['location']);
        self::setSetting($section, '2373f7a4', 'title', $context['h1']);
        $paragraphs = [
            self::localIntroSentence($context),
            'This page is focused specifically on ' . $context['service'] . ' in ' . $context['location'] .
                ', with service information, nearby coverage, frequently asked questions, and a direct way to request help.',
        ];
        self::setSetting($section, '3d63ecb9', 'editor', self::paragraphs($paragraphs));
    }

    private static function composeServiceDetails(array &$section, array $context): void
    {
        self::setSetting($section, '329dfa28', 'text', 'Nearby ' . $context['service'] . ' Services');
        self::setSetting($section, '10b9ef33', 'title', $context['service'] . ' for Homes, Businesses & Projects');

        $details = $context['business'] . ' provides ' . $context['service'] . ' in ' . $context['location'] . '.';
        if ($context['county'] !== '') {
            $county_label = preg_match('/\bcounty$/i', $context['county'])
                ? $context['county']
                : $context['county'] . ' County';
            $details .= ' Service information also reflects coverage in ' . $county_label . ' where applicable.';
        }
        $details .= ' Review the related options below to find the service that best matches the work you need completed.';
        self::setSetting($section, '5239f9c6', 'editor', '<p>' . self::e($details) . '</p>');

        $services = !empty($context['related']) ? $context['related'] : $context['variations'];
        if (empty($services)) {
            $services = [$context['service']];
        }
        $midpoint = (int)ceil(count($services) / 2);
        self::setIconList($section, '44c68d5d', array_slice($services, 0, $midpoint), array_slice($context['related_links'], 0, $midpoint));
        self::setIconList($section, '38553e01', array_slice($services, $midpoint), array_slice($context['related_links'], $midpoint));
    }

    private static function composeWhyChoose(array &$section, array $context): void
    {
        self::setSetting($section, '55d4a623', 'text', 'Why Choose Us');
        self::setSetting($section, '6228afc4', 'title', 'Why Choose ' . $context['service']);
        self::setSetting(
            $section,
            '383c52aa',
            'editor',
            '<p>' . self::e('Use this section to compare the scheduling, pricing, handling, and cleanup details for ' .
                $context['service'] . ' in ' . $context['location'] . '.') . '</p>'
        );
        $items = self::trustItems();
        $ids = ['53fda356', '75054ec0', '66fb2ff4', '59813f88'];
        foreach ($ids as $index => $widget_id) {
            self::setSetting($section, $widget_id, 'title_text', $items[$index][0]);
            self::setSetting($section, $widget_id, 'description_text', $items[$index][1]);
        }
    }

    private static function composeGallery(array &$section, array $context): void
    {
        self::setSetting($section, '76426e0b', 'text', 'Real Results');
        self::setSetting($section, '57b1e612', 'title', 'Recent ' . $context['service'] . ' Projects');
        // The nested carousel is intentionally untouched so every image and display setting remains exact.
    }

    private static function composeReviews(array &$section, array $context): void
    {
        self::setSetting($section, '16e840a6', 'text', 'Trusted by Local Customers');
        self::setSetting($section, 'e72c226', 'title', $context['service'] . ' in ' . $context['location']);
        self::setSetting(
            $section,
            '70e739bb',
            'editor',
            '<p>' . self::e('Read recent customer feedback before requesting ' . $context['service'] . ' in ' . $context['city'] . '.') . '</p>'
        );
        // The Trustindex shortcode widget is intentionally untouched.
    }

    private static function composeServiceArea(array &$section, array $context): void
    {
        self::setSetting($section, '3ed9057a', 'text', 'Nearby Service Areas');
        self::setSetting($section, '1fba948', 'title', $context['service'] . ' Near ' . $context['city']);
        self::setSetting(
            $section,
            'e9a3c2e',
            'editor',
            '<p>' . self::e($context['business'] . ' serves ' . $context['city'] . ' and nearby communities with ' .
                $context['service'] . '.') . '</p>'
        );

        self::removeById($section, '6660eb61');
        self::replaceLocationCards($section, $context);
    }

    private static function composeFaq(array &$section, array $context): void
    {
        self::setSetting($section, '44b84554', 'text', 'FAQ');
        self::setSetting($section, '5ea069e9', 'title', $context['city'] . ' ' . $context['service'] . ' FAQs');
        self::setSetting(
            $section,
            '3b6a9706',
            'editor',
            '<p>' . self::e('Review common questions about ' . $context['service'] . ' in ' . $context['location'] . '.') . '</p>'
        );

        $faqs = self::faqs($context);
        self::mutateById($section, '7a20d44a', static function (array &$widget) use ($faqs, $context): void {
            $prototype = isset($widget['elements'][0]) && is_array($widget['elements'][0])
                ? $widget['elements'][0]
                : self::faqAnswerContainer($context, 'Answer unavailable.');
            $items = [];
            $elements = [];
            foreach ($faqs as $index => $faq) {
                $item_id = self::stableId($context, 'faq-item-' . $index);
                $items[] = ['item_title' => $faq[0], '_id' => $item_id];
                $answer = $prototype;
                self::refreshIds($answer, $context, 'faq-' . $index);
                self::setFirstWidgetSetting($answer, 'text-editor', 'editor', '<p>' . self::e($faq[1]) . '</p>');
                $elements[] = $answer;
            }
            $widget['settings']['items'] = $items;
            $widget['elements'] = $elements;
        });
    }

    private static function composeFinalCta(array &$section, array $context): void
    {
        $title = $context['cta_title'] ?: 'Need ' . $context['service'] . ' in ' . $context['city'] . '?';
        $text = $context['cta_text'] ?: 'Contact ' . $context['business'] . ' to request service details.';
        self::setSetting($section, '144cf123', 'title', $title);
        self::setSetting($section, '3cfb9fbf', 'editor', '<p>' . self::e($text) . '</p>');
    }

    private static function replaceLocationCards(array &$section, array $context): void
    {
        $labels = [];
        foreach ($context['nearby'] as $nearby) {
            $labels[] = self::locationLabel($nearby, $context['state']);
        }
        $labels = array_values(array_unique(array_filter($labels)));
        $links = $context['nearby_links'];

        self::replaceChildrenByIds(
            $section,
            self::LOCATION_WIDGETS,
            static function (array $prototype) use ($labels, $links, $context): array {
                $cards = [];
                foreach ($labels as $index => $label) {
                    $card = $prototype;
                    self::refreshIds($card, $context, 'location-' . $index);
                    $card['settings']['title_text'] = $label;
                    $link = isset($card['settings']['link']) && is_array($card['settings']['link'])
                        ? $card['settings']['link']
                        : [];
                    $card['settings']['link'] = array_merge(
                        [
                            'url'               => '',
                            'is_external'       => '',
                            'nofollow'          => '',
                            'custom_attributes' => '',
                        ],
                        $link
                    );
                    $card['settings']['link']['url'] = (string) ($links[$index] ?? '');
                    $cards[] = $card;
                }
                return $cards;
            }
        );
    }

    private static function contactSection(array $style_source, array $context): array
    {
        $section = [
            'id'       => self::stableId($context, 'contact-section'),
            'settings' => $style_source['settings'] ?? ['flex_direction' => 'column'],
            'elements' => [],
            'isInner'  => false,
            'elType'   => 'container',
        ];

        $heading = [
            'id'         => self::stableId($context, 'contact-heading'),
            'settings'   => ['title' => 'Request ' . $context['service'] . ' in ' . $context['city'], 'header_size' => 'h2'],
            'elements'   => [],
            'isInner'    => false,
            'widgetType' => 'heading',
            'elType'     => 'widget',
        ];
        $intro = [
            'id'         => self::stableId($context, 'contact-intro'),
            'settings'   => ['editor' => '<p>' . self::e('Send ' . $context['business'] . ' the details needed to follow up about ' . $context['service'] . ' in ' . $context['location'] . '.') . '</p>'],
            'elements'   => [],
            'isInner'    => false,
            'widgetType' => 'text-editor',
            'elType'     => 'widget',
        ];

        $fields = [
            ['Name', 'text', true],
            ['Phone', 'tel', true],
            ['Email', 'email', true],
            ['Service address or ZIP code', 'text', true],
            ['Service needed', 'text', true],
            ['Items or project details', 'textarea', false],
            ['Preferred timeframe', 'text', false],
            ['Photo upload', 'upload', false],
            ['Additional details', 'textarea', false],
        ];
        $form_fields = [];
        foreach ($fields as $index => $field) {
            $form_fields[] = [
                'custom_id'  => 'field_' . ($index + 1),
                'field_type' => $field[1],
                'field_label'=> $field[0],
                'placeholder'=> $field[0],
                'required'   => $field[2] ? 'true' : '',
                'width'      => '100',
                '_id'        => self::stableId($context, 'contact-field-' . $index),
            ];
        }

        $form = [
            'id'         => self::stableId($context, 'contact-form'),
            'settings'   => [
                'form_name'   => $context['service'] . ' Request - ' . $context['city'],
                'form_fields' => $form_fields,
                'button_text' => 'Send Request',
                'submit_actions' => ['email'],
                'email_to'    => $context['email'],
                'email_subject' => 'New ' . $context['service'] . ' request in ' . $context['city'],
                'email_content' => '[all-fields]',
                'success_message' => 'Thank you. Your request has been sent.',
            ],
            'elements'   => [],
            'isInner'    => false,
            'widgetType' => 'form',
            'elType'     => 'widget',
        ];

        $section['elements'] = [$heading, $intro, $form];
        return $section;
    }

    private static function summaryBody(array $context): string
    {
        return '<h1>' . self::e($context['h1']) . '</h1>' .
            '<p>' . self::e(self::localIntroSentence($context)) . '</p>' .
            '<p>' . self::e($context['cta_text']) . '</p>';
    }

    private static function localIntroSentence(array $context): string
    {
        return $context['business'] . ' provides ' . $context['service'] . ' in ' . $context['location'] .
            '. Use this page to review service details, nearby coverage, customer feedback, and request options.';
    }

    private static function trustItems(): array
    {
        return [
            ['Fast Scheduling', 'Get a quick quote and choose a convenient service time.'],
            ['Upfront Pricing', 'Receive clear pricing before the work begins.'],
            ['Heavy Lifting Handled', 'Our team handles the lifting, loading, and hauling.'],
            ['Clean Finish', 'We remove the unwanted items and leave the cleared area ready to use.'],
        ];
    }

    private static function faqs(array $context): array
    {
        $service = $context['service'];
        $city = $context['city'];
        $business = $context['business'];
        if (stripos($service, 'junk') !== false || stripos($service, 'removal') !== false) {
            return [
                ['What items can you remove?', $business . ' can confirm accepted items when you describe the material, quantity, and access details.'],
                ['Do I need to move everything outside?', 'Share where the items are located so the team can explain the available pickup and removal options.'],
                ['Do you provide commercial junk removal?', 'Use the request form to describe the commercial property, material, access, and preferred timeframe.'],
                ['Can you clean out an entire property?', 'Property cleanout availability depends on the project size and scope. Send the address and project details for review.'],
                ['Do you offer dumpster rentals in ' . $city . '?', 'Ask about dumpster rental availability, sizing, placement, and scheduling for your address.'],
                ['How much does junk removal cost?', 'Pricing depends on the items, volume, labor, access, and disposal requirements. Request a quote for the current project.'],
                ['Do you remove hazardous materials?', 'Describe any potentially hazardous material before scheduling so accepted and excluded items can be confirmed.'],
            ];
        }

        return [
            ['What is included with ' . $service . '?', 'The included work depends on the requested scope. Send the project details for a clear explanation.'],
            ['Is ' . $service . ' available in ' . $city . '?', $business . ' lists ' . $city . ' as the target location for this service page.'],
            ['How do I request a quote?', 'Use the contact form with your address, service needed, and project details.'],
            ['What information should I provide?', 'Include the service address, project scope, preferred timeframe, and photos when helpful.'],
            ['Can businesses request this service?', 'Describe the commercial location and project requirements so availability can be confirmed.'],
            ['How is pricing determined?', 'Pricing depends on the project scope, labor, materials, access, and other service-specific requirements.'],
            ['What happens after I submit the form?', $business . ' can review the details and follow up about availability and next steps.'],
        ];
    }

    private static function setIconList(array &$root, string $id, array $labels, array $links): void
    {
        self::mutateById($root, $id, static function (array &$widget) use ($labels, $links, $id): void {
            $prototype = isset($widget['settings']['icon_list'][0]) && is_array($widget['settings']['icon_list'][0])
                ? $widget['settings']['icon_list'][0]
                : ['selected_icon' => ['value' => 'fas fa-circle', 'library' => 'fa-solid']];
            $items = [];
            foreach ($labels as $index => $label) {
                $item = $prototype;
                $item['text'] = $label;
                $item['_id'] = substr(md5($id . '|' . $index . '|' . $label), 0, 7);
                if (!isset($item['link']) || !is_array($item['link'])) {
                    $item['link'] = ['url' => '', 'is_external' => '', 'nofollow' => '', 'custom_attributes' => ''];
                }
                $item['link']['url'] = $links[$index] ?? '';
                $items[] = $item;
            }
            $widget['settings']['icon_list'] = $items;
        });
    }

    private static function setSetting(array &$root, string $id, string $key, $value): void
    {
        self::mutateById($root, $id, static function (array &$node) use ($key, $value): void {
            if (!isset($node['settings']) || !is_array($node['settings'])) {
                $node['settings'] = [];
            }
            $node['settings'][$key] = $value;
        });
    }

    private static function mutateById(array &$root, string $id, callable $callback): bool
    {
        if ((string)($root['id'] ?? '') === $id) {
            $callback($root);
            return true;
        }
        if (!isset($root['elements']) || !is_array($root['elements'])) {
            return false;
        }
        foreach ($root['elements'] as &$child) {
            if (is_array($child) && self::mutateById($child, $id, $callback)) {
                unset($child);
                return true;
            }
        }
        unset($child);
        return false;
    }

    private static function removeById(array &$root, string $id): void
    {
        if (!isset($root['elements']) || !is_array($root['elements'])) {
            return;
        }
        foreach ($root['elements'] as $index => &$child) {
            if (!is_array($child)) {
                continue;
            }
            if ((string)($child['id'] ?? '') === $id) {
                unset($root['elements'][$index]);
                $root['elements'] = array_values($root['elements']);
                unset($child);
                return;
            }
            self::removeById($child, $id);
        }
        unset($child);
    }

    private static function replaceChildrenByIds(array &$root, array $ids, callable $builder): bool
    {
        if (isset($root['elements']) && is_array($root['elements'])) {
            foreach ($root['elements'] as $index => $child) {
                if (is_array($child) && in_array((string)($child['id'] ?? ''), $ids, true)) {
                    $prototype = $child;
                    $kept = [];
                    $insert_at = null;
                    foreach ($root['elements'] as $current) {
                        if (is_array($current) && in_array((string)($current['id'] ?? ''), $ids, true)) {
                            if ($insert_at === null) {
                                $insert_at = count($kept);
                            }
                            continue;
                        }
                        $kept[] = $current;
                    }
                    $new_children = $builder($prototype);
                    array_splice($kept, $insert_at ?? $index, 0, $new_children);
                    $root['elements'] = array_values($kept);
                    return true;
                }
            }
            foreach ($root['elements'] as &$child) {
                if (is_array($child) && self::replaceChildrenByIds($child, $ids, $builder)) {
                    unset($child);
                    return true;
                }
            }
            unset($child);
        }
        return false;
    }

    private static function setFirstWidgetSetting(array &$root, string $widget_type, string $key, $value): bool
    {
        if (($root['widgetType'] ?? '') === $widget_type) {
            $root['settings'][$key] = $value;
            return true;
        }
        if (!isset($root['elements']) || !is_array($root['elements'])) {
            return false;
        }
        foreach ($root['elements'] as &$child) {
            if (is_array($child) && self::setFirstWidgetSetting($child, $widget_type, $key, $value)) {
                unset($child);
                return true;
            }
        }
        unset($child);
        return false;
    }

    private static function refreshIds(array &$node, array $context, string $seed): void
    {
        $node['id'] = self::stableId($context, $seed . '-root');
        if (!isset($node['elements']) || !is_array($node['elements'])) {
            return;
        }
        foreach ($node['elements'] as $index => &$child) {
            if (is_array($child)) {
                self::refreshIds($child, $context, $seed . '-' . $index);
            }
        }
        unset($child);
    }

    private static function stableId(array $context, string $seed): string
    {
        return substr(md5(($context['row']['slug'] ?? '') . '|' . $seed), 0, 8);
    }

    private static function faqAnswerContainer(array $context, string $answer): array
    {
        return [
            'id' => self::stableId($context, 'faq-answer-container'),
            'settings' => [],
            'elements' => [[
                'id' => self::stableId($context, 'faq-answer-text'),
                'settings' => ['editor' => '<p>' . self::e($answer) . '</p>'],
                'elements' => [],
                'isInner' => false,
                'widgetType' => 'text-editor',
                'elType' => 'widget',
            ]],
            'isInner' => true,
            'elType' => 'container',
        ];
    }

    private static function locationLabel(string $location, string $state): string
    {
        if ($state !== '' && stripos($location, $state) === false && strpos($location, ',') === false) {
            return $location . ', ' . $state;
        }
        return $location;
    }

    private static function splitList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        $items = preg_split('/\s*(?:;|\||\r?\n)\s*/', trim($value));
        if (!is_array($items)) {
            return [];
        }
        return array_values(array_filter(array_map('trim', $items), static fn(string $item): bool => $item !== ''));
    }

    private static function paragraphs(array $paragraphs): string
    {
        return implode('', array_map(static fn(string $paragraph): string => '<p>' . self::e($paragraph) . '</p>', $paragraphs));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

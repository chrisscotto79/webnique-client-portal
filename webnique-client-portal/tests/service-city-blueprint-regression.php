<?php
/**
 * Regression checks for deterministic Service + City Elementor composition.
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require_once dirname(__DIR__) . '/includes/Services/ServiceCityPageBlueprint.php';

use WNQ\Services\ServiceCityPageBlueprint;

function failTest(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assertTest(bool $condition, string $message): void
{
    if (!$condition) {
        failTest($message);
    }
}

function findById(array $nodes, string $id): ?array
{
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        if ((string)($node['id'] ?? '') === $id) {
            return $node;
        }
        if (isset($node['elements']) && is_array($node['elements'])) {
            $match = findById($node['elements'], $id);
            if ($match !== null) {
                return $match;
            }
        }
    }

    return null;
}

function findByWidgetType(array $nodes, string $widget_type): ?array
{
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        if ((string)($node['widgetType'] ?? '') === $widget_type) {
            return $node;
        }
        if (isset($node['elements']) && is_array($node['elements'])) {
            $match = findByWidgetType($node['elements'], $widget_type);
            if ($match !== null) {
                return $match;
            }
        }
    }

    return null;
}

function collectIds(array $nodes, array &$ids): void
{
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        $id = (string)($node['id'] ?? '');
        if ($id !== '') {
            $ids[] = $id;
        }
        if (isset($node['elements']) && is_array($node['elements'])) {
            collectIds($node['elements'], $ids);
        }
    }
}

function collectNodesWithSetting(array $nodes, string $key, array &$matches): void
{
    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }
        if (isset($node['settings']) && is_array($node['settings']) && array_key_exists($key, $node['settings'])) {
            $matches[] = $node;
        }
        if (isset($node['elements']) && is_array($node['elements'])) {
            collectNodesWithSetting($node['elements'], $key, $matches);
        }
    }
}

$source_path = $argv[1] ?? '/Users/scotto/Downloads/elementor-416-2026-07-17.json';
if (!is_readable($source_path)) {
    failTest('Source Elementor export is not readable: ' . $source_path);
}

$source_json = file_get_contents($source_path);
assertTest(is_string($source_json) && $source_json !== '', 'Source Elementor export is empty.');
$source = json_decode($source_json, true);
assertTest(is_array($source), 'Source Elementor export is invalid JSON.');

$row = [
    'primary_keyword'                    => 'junk removal Pine Island',
    'service'                            => 'Junk Removal',
    'service_variations'                 => 'Residential Junk Removal;Commercial Junk Removal',
    'city'                               => 'Pine Island',
    'state'                              => 'FL',
    'county'                             => 'Lee County',
    'slug'                               => 'junk-removal-pine-island-fl',
    'page_title'                         => 'Junk Removal in Pine Island, FL',
    'title_tag'                          => 'Junk Removal in Pine Island, FL | Blue Tide Hauling',
    'meta_description'                   => 'Local junk removal in Pine Island, FL with simple scheduling and clear communication.',
    'h1'                                 => 'Junk Removal in Pine Island, FL',
    'cta_title'                          => 'Need Junk Removal in Pine Island?',
    'cta_text'                           => 'Request a quote for junk removal in Pine Island.',
    'related_services'                   => 'Dumpster Rentals;Furniture Removal;Appliance Removal',
    'navigation_menu_related_services'   => '/dumpster-rentals/;/furniture-removal/;/appliance-removal/',
    'nearby_cities'                      => 'Cape Coral;Fort Myers;Matlacha',
    'nav_menu_nearby_areas'              => '/cape-coral/;/fort-myers/;/matlacha/',
    'internal_links'                     => '/junk-removal/;/contact/',
    'geo_modifiers'                      => 'Pine Island;Lee County;Southwest Florida',
    'commercial_intent'                  => 'high',
    'page_type'                          => 'service_city_page',
    'parent_service_slug'                => 'junk-removal',
];

$result = ServiceCityPageBlueprint::compose(
    $source_json,
    $row,
    'Blue Tide Hauling LLC',
    ['phone' => '(239) 994-6651', 'email' => 'info@example.com'],
    []
);

assertTest(($result['recognized'] ?? false) === true, 'Approved Elementor export was not recognized.');
assertTest(($result['success'] ?? false) === true, (string)($result['message'] ?? 'Composition failed.'));

$output = json_decode((string)($result['built']['elementor_data'] ?? ''), true);
assertTest(is_array($output), 'Composed Elementor data is invalid JSON.');
assertTest(count($output) === 11, 'Expected exactly 11 top-level sections.');

$expected_order = [
    '60181fdf',
    '69228ce4',
    '180e7e80',
    '89a00ae',
    '7e91beaa',
    '1d932af2',
    '489da4cf',
    '46f79439',
    '24051db0',
];
$actual_order = array_map(static fn(array $section): string => (string)($section['id'] ?? ''), array_slice($output, 0, 9));
assertTest($actual_order === $expected_order, 'Top-level section order changed.');
assertTest((string)($output[10]['id'] ?? '') === '5f026f7c', 'Final CTA is not the last section.');
assertTest(!in_array((string)($output[9]['id'] ?? ''), $expected_order, true), 'Contact form did not receive a fresh section ID.');
assertTest((string)(findById($output, '2373f7a4')['settings']['title'] ?? '') === $row['h1'], 'Local intro H1 does not match the CSV H1.');

$related_items = [];
foreach (['44c68d5d', '38553e01'] as $widget_id) {
    $widget = findById($output, $widget_id);
    assertTest($widget !== null, 'Related-service navigation widget is missing: ' . $widget_id);
    foreach (($widget['settings']['icon_list'] ?? []) as $item) {
        $related_items[] = [
            (string)($item['text'] ?? ''),
            (string)($item['link']['url'] ?? ''),
        ];
    }
}
assertTest($related_items === [
    ['Dumpster Rentals', '/dumpster-rentals/'],
    ['Furniture Removal', '/furniture-removal/'],
    ['Appliance Removal', '/appliance-removal/'],
], 'Related service labels and links no longer align.');

$source_content = isset($source['content']) && is_array($source['content']) ? $source['content'] : $source;
$source_gallery = findById($source_content, '62ed51f6');
$output_gallery = findById($output, '62ed51f6');
assertTest($source_gallery !== null && $output_gallery !== null, 'Gallery widget is missing.');
assertTest(($source_gallery['settings'] ?? null) === ($output_gallery['settings'] ?? null), 'Gallery settings changed.');

$reviews = findById($output, '728de910');
assertTest($reviews !== null, 'Review widget is missing.');
assertTest((string)($reviews['settings']['shortcode'] ?? '') === '[trustindex no-registration=google]', 'Review shortcode changed.');
assertTest(findById($output, '6660eb61') === null, 'Obsolete service-area phone CTA remains in the output.');

$service_area = findById($output, '46f79439');
assertTest($service_area !== null, 'Service-area section is missing.');
assertTest((string)(findById([$service_area], '3ed9057a')['settings']['text'] ?? '') === 'Nearby Service Areas', 'Service-area eyebrow is incorrect.');
assertTest((string)(findById([$service_area], '1fba948')['settings']['title'] ?? '') === 'Junk Removal Near Pine Island', 'Service-area heading is incorrect.');
$service_area_intro = (string)(findById([$service_area], 'e9a3c2e')['settings']['editor'] ?? '');
assertTest(strpos($service_area_intro, 'Blue Tide Hauling LLC') !== false, 'Service-area introduction is missing the business name.');
assertTest(strpos($service_area_intro, 'Pine Island') !== false, 'Service-area introduction is missing the current city.');
assertTest(strpos($service_area_intro, 'Junk Removal') !== false, 'Service-area introduction is missing the current service.');

$location_cards = [];
collectNodesWithSetting([$service_area], 'title_text', $location_cards);
$location_cards = array_values(array_filter($location_cards, static function (array $node): bool {
    return in_array((string)($node['settings']['title_text'] ?? ''), ['Cape Coral, FL', 'Fort Myers, FL', 'Matlacha, FL', 'Pine Island, FL'], true);
}));
$location_pairs = array_map(static function (array $node): array {
    return [
        (string)($node['settings']['title_text'] ?? ''),
        (string)($node['settings']['link']['url'] ?? ''),
    ];
}, $location_cards);
assertTest($location_pairs === [
    ['Cape Coral, FL', '/cape-coral/'],
    ['Fort Myers, FL', '/fort-myers/'],
    ['Matlacha, FL', '/matlacha/'],
], 'Nearby city cards contain the wrong labels, order, or navigation links.');

$faq = findById($output, '7a20d44a');
assertTest($faq !== null, 'FAQ widget is missing.');
assertTest(count($faq['settings']['items'] ?? []) === 7, 'FAQ settings do not contain seven items.');
assertTest(count($faq['elements'] ?? []) === 7, 'FAQ child elements do not contain seven items.');
assertTest((string)(findById($output, '5ea069e9')['settings']['title'] ?? '') === 'Pine Island Junk Removal FAQs', 'FAQ heading is not service and city specific.');

$form = findByWidgetType($output, 'form');
assertTest($form !== null, 'Contact form widget is missing.');
assertTest(count($form['settings']['form_fields'] ?? []) === 9, 'Contact form does not contain nine fields.');
$contact_heading = findByWidgetType([$output[9]], 'heading');
assertTest((string)($contact_heading['settings']['title'] ?? '') === 'Request Junk Removal in Pine Island', 'Contact heading is incorrect.');

assertTest((string)(findById($output, '144cf123')['settings']['title'] ?? '') === $row['cta_title'], 'Final CTA title does not match the CSV value.');
assertTest((string)(findById($output, '3cfb9fbf')['settings']['editor'] ?? '') === '<p>' . $row['cta_text'] . '</p>', 'Final CTA text does not match the CSV value.');

$ids = [];
collectIds($output, $ids);
assertTest(count($ids) === count(array_unique($ids)), 'Composed Elementor data contains duplicate element IDs.');

$encoded = (string)($result['built']['elementor_data'] ?? '');
assertTest(stripos($encoded, 'County County') === false, 'County label was duplicated.');

$city_only = $row;
$city_only['page_type'] = 'city';
assertTest(ServiceCityPageBlueprint::validateRow($city_only)['valid'] === false, 'City-only row was accepted.');

$bad_h1 = $row;
$bad_h1['h1'] = 'Local Help Near You';
assertTest(ServiceCityPageBlueprint::validateRow($bad_h1)['valid'] === false, 'H1 without service and city was accepted.');

$bad_related_links = $row;
$bad_related_links['navigation_menu_related_services'] = '/dumpster-rentals/;/furniture-removal/';
assertTest(ServiceCityPageBlueprint::validateRow($bad_related_links)['valid'] === false, 'Mismatched related-service labels and links were accepted.');

$legacy_row = $row;
$legacy_row['page_type'] = 'page';
$legacy_row['related_services'] = 'Junk Removal;Dumpster Rentals;Furniture Removal';
$legacy_row['navigation_menu_related_services'] = '';
$legacy_row['internal_links'] = '/service-areas/pine-island-fl/;/dumpster-rentals/;/furniture-removal/';
$normalized_legacy_row = ServiceCityPageBlueprint::normalizeImportRow($legacy_row);
assertTest($normalized_legacy_row['page_type'] === 'service_city', 'Legacy Service + City page type was not normalized.');
assertTest(
    $normalized_legacy_row['navigation_menu_related_services'] === '/service-areas/pine-island-fl/;/dumpster-rentals/;/furniture-removal/',
    'Legacy related-service links were not reconstructed in label order.'
);
assertTest(ServiceCityPageBlueprint::validateRow($normalized_legacy_row)['valid'] === true, 'Normalized legacy row did not pass validation.');

$header_value_row = $legacy_row;
$header_value_row['navigation_menu_related_services'] = 'navigation_menu_related_services';
$normalized_header_value_row = ServiceCityPageBlueprint::normalizeImportRow($header_value_row);
assertTest(
    $normalized_header_value_row['navigation_menu_related_services'] === '/service-areas/pine-island-fl/;/dumpster-rentals/;/furniture-removal/',
    'Accidental header value was not repaired.'
);

$legacy_city_only = $legacy_row;
$legacy_city_only['page_type'] = 'city';
assertTest(
    ServiceCityPageBlueprint::validateRow(ServiceCityPageBlueprint::normalizeImportRow($legacy_city_only))['valid'] === false,
    'A genuine city-only row was normalized and accepted.'
);

$unresolvable_legacy_row = $legacy_row;
$unresolvable_legacy_row['internal_links'] = '/service-areas/pine-island-fl/;/dumpster-rentals/';
$normalized_unresolvable_row = ServiceCityPageBlueprint::normalizeImportRow($unresolvable_legacy_row);
assertTest(
    ServiceCityPageBlueprint::validateRow($normalized_unresolvable_row)['valid'] === false,
    'A legacy row with an unresolved related-service link was accepted.'
);

$sample_pages = [
    ['Junk Removal', 'Fort Myers', 'junk-removal-fort-myers-fl'],
    ['Appliance Removal', 'Fort Myers', 'appliance-removal-fort-myers-fl'],
    ['Dumpster Rentals', 'Naples', 'dumpster-rentals-naples-fl'],
];
foreach ($sample_pages as $sample) {
    $sample_row = $row;
    $sample_row['service'] = $sample[0];
    $sample_row['city'] = $sample[1];
    $sample_row['slug'] = $sample[2];
    $sample_row['primary_keyword'] = strtolower($sample[0] . ' ' . $sample[1]);
    $sample_row['h1'] = $sample[0] . ' in ' . $sample[1] . ', FL';
    $sample_row['page_title'] = $sample_row['h1'];
    $sample_row['title_tag'] = $sample_row['h1'] . ' | Blue Tide Hauling';
    $sample_row['cta_title'] = 'Need ' . $sample[0] . ' in ' . $sample[1] . '?';
    $sample_row['cta_text'] = 'Request a quote for ' . strtolower($sample[0]) . ' in ' . $sample[1] . '.';
    $sample_row['parent_service_slug'] = trim(str_replace(' ', '-', strtolower($sample[0])), '-');

    $sample_result = ServiceCityPageBlueprint::compose($source_json, $sample_row, 'Blue Tide Hauling LLC', [], []);
    assertTest(($sample_result['success'] ?? false) === true, 'Composition failed for ' . $sample_row['h1'] . '.');
    $sample_output = json_decode((string)($sample_result['built']['elementor_data'] ?? ''), true);
    assertTest(is_array($sample_output) && count($sample_output) === 11, 'Section count changed for ' . $sample_row['h1'] . '.');
    assertTest((string)(findById($sample_output, '2373f7a4')['settings']['title'] ?? '') === $sample_row['h1'], 'H1 changed for ' . $sample_row['h1'] . '.');
}

fwrite(STDOUT, "PASS: Service + City blueprint regression checks completed.\n");

<?php
/**
 * Elementor Section Library
 *
 * Provides controlled, reusable Elementor section templates for the AI
 * Elementor builder. These templates are filled with variables instead of
 * allowing AI to invent random Elementor layouts.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class ElementorSectionLibrary
{
    public const LOCAL_SERVICE_HERO = 'local_service_hero';

    public static function templates(): array
    {
        return [
            self::LOCAL_SERVICE_HERO => [
                'label'       => 'Local Service Hero - Slideshow',
                'description' => 'Hero section with H1, intro copy, two CTA buttons, optional slideshow images, and optional social link.',
            ],
        ];
    }

    public static function template(string $key): ?array
    {
        if ($key !== self::LOCAL_SERVICE_HERO) {
            return null;
        }

        return self::localServiceHeroTemplate();
    }

    public static function templateJson(string $key): string
    {
        $template = self::template($key);
        if (!$template) {
            return '';
        }

        return (string)wp_json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function defaults(string $key): array
    {
        if ($key !== self::LOCAL_SERVICE_HERO) {
            return [];
        }

        return [
            'primary_keyword'                 => '',
            'h1'                              => '',
            'hero_subtitle'                   => '',
            'hero_shadow_text'                => '',
            'hero_description'                => '',
            'cta_button_1_text'               => 'Get a Free Estimate',
            'cta_button_1_url'                => '/contact/',
            'cta_button_2_text'               => 'Call Now',
            'cta_button_2_url'                => '/contact/',
            'hero_background_placeholder_url' => '',
            'hero_slide_1_url'                => '',
            'hero_slide_2_url'                => '',
            'hero_slide_3_url'                => '',
            'hero_slide_4_url'                => '',
            'social_heading'                  => 'Follow Us:',
            'social_platform_1_label'         => 'Facebook',
            'social_platform_1_icon'          => 'fab fa-facebook',
            'social_platform_1_icon_library'  => 'fa-brands',
            'social_platform_1_url'           => '',
            'template_title'                  => 'Local Service Hero Section',
        ];
    }

    public static function exampleVariables(string $key = self::LOCAL_SERVICE_HERO): array
    {
        if ($key !== self::LOCAL_SERVICE_HERO) {
            return [];
        }

        return array_merge(self::defaults($key), [
            'primary_keyword'                 => 'well drilling Sarasota FL',
            'service'                         => 'Well Drilling',
            'city'                            => 'Sarasota',
            'state'                           => 'FL',
            'h1'                              => 'Trusted Well Drilling in Sarasota, FL',
            'hero_subtitle'                   => 'Local well drilling, pump repair, and water filtration services.',
            'hero_description'                => 'We help homeowners and businesses get reliable water systems with fast service, honest estimates, and local expertise.',
            'cta_button_1_text'               => 'Emergency No Water?',
            'cta_button_1_url'                => '/emergency-no-water/',
            'cta_button_2_text'               => 'Call For Well Service',
            'cta_button_2_url'                => '/contact/',
            'hero_background_placeholder_url' => 'https://parrishwelldrillingfl.com/wp-content/uploads/2026/01/470184985_122196494792084097_7800433478876490863_n.jpg',
            'hero_slide_1_url'                => 'https://parrishwelldrillingfl.com/wp-content/uploads/2026/01/470184985_122196494792084097_7800433478876490863_n.jpg',
            'hero_slide_2_url'                => 'https://parrishwelldrillingfl.com/wp-content/uploads/2026/01/473590834_122201731094084097_8494315422479041547_n.jpg',
            'hero_slide_3_url'                => 'https://parrishwelldrillingfl.com/wp-content/uploads/2026/01/559732801_122248345160084097_6652787785059867957_n.jpg',
            'hero_slide_4_url'                => 'https://parrishwelldrillingfl.com/wp-content/uploads/2026/01/549417258_122245962104084097_8348665285790414696_n.jpg',
            'social_platform_1_url'           => 'https://www.facebook.com/p/Parrish-Well-Drilling-61552522930790/',
            'title_tag'                       => 'Well Drilling in Sarasota FL | Free Estimates',
            'meta_description'                => 'Need well drilling in Sarasota FL? Get reliable well drilling, pump repair, and filtration service from a trusted local team.',
        ]);
    }

    private static function localServiceHeroTemplate(): array
    {
        return [
            'content' => [
                [
                    'id'       => '3e66038e',
                    'settings' => [
                        'flex_direction'                  => 'column',
                        'padding'                         => ['unit' => 'px', 'top' => '200', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false],
                        'background_background'           => 'slideshow',
                        'background_image'                => ['id' => '', 'url' => '{{hero_background_placeholder_url}}'],
                        'background_position'             => 'center center',
                        'background_attachment'           => 'scroll',
                        'background_repeat'               => 'no-repeat',
                        'background_size'                 => 'cover',
                        'background_overlay_background'   => 'classic',
                        'background_overlay_opacity'      => ['unit' => 'px', 'size' => 0.8, 'sizes' => []],
                        '__globals__'                     => ['background_overlay_color' => 'globals/colors?id=secondary'],
                        'background_slideshow_gallery'    => [
                            ['id' => '', 'url' => '{{hero_slide_1_url}}'],
                            ['id' => '', 'url' => '{{hero_slide_2_url}}'],
                            ['id' => '', 'url' => '{{hero_slide_3_url}}'],
                            ['id' => '', 'url' => '{{hero_slide_4_url}}'],
                        ],
                        'background_slideshow_slide_duration'      => 2000,
                        'background_slideshow_transition_duration' => 2000,
                        'flex_direction_tablet'                    => 'column',
                        'padding_mobile'                           => ['unit' => 'px', 'top' => '130', 'right' => '20', 'bottom' => '50', 'left' => '20', 'isLinked' => false],
                        'background_slideshow_background_size'     => 'cover',
                        'background_slideshow_background_position' => 'center center',
                        'background_slideshow_lazyload'            => 'yes',
                        'background_slideshow_ken_burns'           => 'yes',
                        'min_height'                               => ['unit' => 'vh', 'size' => '', 'sizes' => []],
                        'min_height_tablet'                        => ['unit' => 'vh', 'size' => '', 'sizes' => []],
                        'min_height_mobile'                        => ['unit' => 'vh', 'size' => '', 'sizes' => []],
                        'margin'                                   => ['unit' => 'px', 'top' => '-100', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false],
                        'flex_justify_content'                     => 'center',
                        'flex_align_items'                         => 'center',
                    ],
                    'elements' => [
                        self::heroContentContainer(),
                        self::heroSocialContainer(),
                    ],
                    'isInner' => false,
                    'elType'  => 'container',
                ],
            ],
            'page_settings' => ['hide_title' => 'yes'],
            'version'       => '0.4',
            'title'         => '{{template_title}}',
            'type'          => 'container',
        ];
    }

    private static function heroContentContainer(): array
    {
        return [
            'id'       => '731f5eba',
            'settings' => [
                'content_width'            => 'full',
                'padding'                  => ['unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false],
                'width_tablet'             => ['unit' => '%', 'size' => 70, 'sizes' => []],
                'width_mobile'             => ['unit' => '%', 'size' => '', 'sizes' => []],
                '_flex_align_self_tablet'  => 'center',
                'width'                    => ['unit' => '%', 'size' => 63, 'sizes' => []],
                'flex_justify_content'     => 'center',
                'flex_align_items'         => 'center',
            ],
            'elements' => [
                [
                    'id'       => '4b4430f6',
                    'settings' => [
                        'title'                        => '{{h1}}',
                        'header_size'                  => 'h1',
                        'align'                        => 'center',
                        'title_color'                  => '#FFFFFF',
                        'typography_typography'        => 'custom',
                        'typography_font_family'       => 'Inter Tight',
                        'typography_font_size'         => ['unit' => 'px', 'size' => 70, 'sizes' => []],
                        'typography_font_size_mobile'  => ['unit' => 'px', 'size' => 36, 'sizes' => []],
                        'typography_font_weight'       => '700',
                        'typography_text_transform'    => 'capitalize',
                        'typography_line_height'       => ['unit' => 'em', 'size' => 1.1, 'sizes' => []],
                        'typography_line_height_mobile'=> ['unit' => 'em', 'size' => 1.15, 'sizes' => []],
                    ],
                    'elements'   => [],
                    'isInner'    => false,
                    'widgetType' => 'heading',
                    'elType'     => 'widget',
                ],
                [
                    'id'       => '7a8f2ed1',
                    'settings' => [
                        'title'                       => '{{hero_subtitle}}',
                        'header_size'                 => 'h2',
                        'align'                       => 'center',
                        'title_color'                 => '#EF470F',
                        'typography_typography'       => 'custom',
                        'typography_font_family'      => 'Inter Tight',
                        'typography_font_size'        => ['unit' => 'px', 'size' => 24, 'sizes' => []],
                        'typography_font_size_mobile' => ['unit' => 'px', 'size' => 18, 'sizes' => []],
                        'typography_font_weight'      => '600',
                    ],
                    'elements'   => [],
                    'isInner'    => false,
                    'widgetType' => 'heading',
                    'elType'     => 'widget',
                ],
                [
                    'id'       => '40ae2419',
                    'settings' => [
                        'align'                 => 'center',
                        '_element_width'        => 'initial',
                        '_element_custom_width' => ['unit' => '%', 'size' => 88, 'sizes' => []],
                        '__globals__'           => [
                            'typography_typography' => 'globals/typography?id=text',
                            'text_color'            => 'globals/colors?id=4cd0a05',
                            'link_hover_color'      => 'globals/colors?id=accent',
                        ],
                        'text_color' => '#FFFFFF',
                        'editor'     => '<p><strong>{{primary_keyword}}</strong> from a trusted local team. {{hero_description}}</p>',
                    ],
                    'elements'   => [],
                    'isInner'    => false,
                    'widgetType' => 'text-editor',
                    'elType'     => 'widget',
                ],
                [
                    'id'       => '59ed1091',
                    'settings' => [
                        'content_width'               => 'full',
                        'flex_direction'              => 'row',
                        'flex_align_items'            => 'center',
                        'padding'                     => ['unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false],
                        'flex_justify_content_tablet' => 'center',
                        'flex_justify_content'        => 'center',
                        'flex_wrap_mobile'            => 'wrap',
                    ],
                    'elements' => [
                        self::heroButton('6bc602ef', '{{cta_button_1_text}}', '{{cta_button_1_url}}', true),
                        self::heroButton('25315c6d', '{{cta_button_2_text}}', '{{cta_button_2_url}}', false),
                    ],
                    'isInner' => true,
                    'elType'  => 'container',
                ],
            ],
            'isInner' => true,
            'elType'  => 'container',
        ];
    }

    private static function heroButton(string $id, string $text, string $url, bool $primary): array
    {
        return [
            'id'       => $id,
            'settings' => [
                'text'          => $text,
                'selected_icon' => ['value' => 'icon icon-arrow-right', 'library' => 'ekiticons'],
                'icon_align'    => 'row-reverse',
                'icon_indent'   => ['unit' => 'px', 'size' => 10, 'sizes' => []],
                '__globals__'   => $primary
                    ? [
                        'typography_typography'       => 'globals/typography?id=secondary',
                        'button_text_color'           => 'globals/colors?id=secondary',
                        'background_color'            => 'globals/colors?id=primary',
                        'border_color'                => 'globals/colors?id=secondary',
                        'hover_color'                 => 'globals/colors?id=accent',
                        'button_background_hover_color'=> 'globals/colors?id=f52e0cf',
                        'button_hover_border_color'   => 'globals/colors?id=accent',
                    ]
                    : [
                        'typography_typography'       => 'globals/typography?id=secondary',
                        'button_text_color'           => 'globals/colors?id=accent',
                        'background_color'            => 'globals/colors?id=f52e0cf',
                        'border_color'                => 'globals/colors?id=accent',
                        'hover_color'                 => 'globals/colors?id=secondary',
                        'button_background_hover_color'=> 'globals/colors?id=primary',
                        'button_hover_border_color'   => 'globals/colors?id=primary',
                    ],
                'border_border' => $primary ? '' : 'solid',
                'border_width'  => $primary
                    ? ['unit' => 'px', 'top' => '1', 'right' => '3', 'bottom' => '3', 'left' => '1', 'isLinked' => false]
                    : ['unit' => 'px', 'top' => '1', 'right' => '1', 'bottom' => '1', 'left' => '1', 'isLinked' => true],
                'border_radius' => ['unit' => 'px', 'top' => '50', 'right' => '50', 'bottom' => '50', 'left' => '50', 'isLinked' => true],
                'button_text_color' => $primary ? '#FFFFFF' : '#FFFFFF',
                'background_color'  => $primary ? '#D9BE42' : '#2D2900',
                'hover_color'       => $primary ? '#FFFFFF' : '#FFFFFF',
                'button_background_hover_color' => $primary ? '#2D2900' : '#D9BE42',
                'link'          => ['url' => $url, 'is_external' => '', 'nofollow' => '', 'custom_attributes' => ''],
            ],
            'elements'   => [],
            'isInner'    => false,
            'widgetType' => 'button',
            'elType'     => 'widget',
        ];
    }

    private static function heroSocialContainer(): array
    {
        return [
            'id'       => '2d198d5',
            'settings' => [
                'content_width'             => 'full',
                'padding'                   => ['unit' => 'px', 'top' => '30', 'right' => '0', 'bottom' => '50', 'left' => '0', 'isLinked' => false],
                'width_tablet'              => ['unit' => '%', 'size' => 53, 'sizes' => []],
                'width_mobile'              => ['unit' => '%', 'size' => '', 'sizes' => []],
                'flex_direction_tablet'     => 'column',
                '_flex_align_self_tablet'   => 'center',
                'flex_direction'            => 'row',
                'width'                     => ['unit' => '%', 'size' => 100, 'sizes' => []],
                'padding_mobile'            => ['unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false],
                'flex_direction_mobile'     => 'row',
                'flex_justify_content_mobile'=> 'center',
                'flex_align_items_mobile'   => 'center',
                'flex_wrap_mobile'          => 'wrap',
                '_flex_align_self'          => 'center',
            ],
            'elements' => [
                [
                    'id'       => '419c9b3d',
                    'settings' => [
                        'content_width'       => 'full',
                        'width'               => ['unit' => '%', 'size' => 30, 'sizes' => []],
                        'padding'             => ['unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false],
                        'width_tablet'        => ['unit' => '%', 'size' => 100, 'sizes' => []],
                        'width_mobile'        => ['unit' => '%', 'size' => '', 'sizes' => []],
                        '_flex_order_tablet'  => 'start',
                    ],
                    'elements' => [
                        [
                            'id'       => '32a1dcab',
                            'settings' => [
                                'content_width'             => 'full',
                                'width'                     => ['unit' => '%', 'size' => 100, 'sizes' => []],
                                'border_radius'             => ['unit' => 'px', 'top' => '10', 'right' => '10', 'bottom' => '10', 'left' => '10', 'isLinked' => true],
                                'padding'                   => ['unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false],
                                '__globals__'               => ['background_color' => 'globals/colors?id=accent'],
                                'flex_direction'            => 'row',
                                'flex_justify_content'      => 'flex-start',
                                'flex_align_items'          => 'center',
                                'flex_direction_mobile'     => 'row',
                                'flex_justify_content_tablet'=> 'center',
                                'flex_wrap_mobile'          => 'wrap',
                            ],
                            'elements' => [
                                [
                                    'id'       => '44e5bbfe',
                                    'settings' => [
                                        'title'       => '{{social_heading}}',
                                        '__globals__' => [
                                            'typography_typography' => 'globals/typography?id=23d71d9',
                                            'title_color'           => 'globals/colors?id=accent',
                                            'title_hover_color'     => 'globals/colors?id=accent',
                                        ],
                                    ],
                                    'elements'   => [],
                                    'isInner'    => false,
                                    'widgetType' => 'heading',
                                    'elType'     => 'widget',
                                ],
                                [
                                    'id'       => '13a88542',
                                    'settings' => [
                                        'text'                         => '{{social_platform_1_label}}',
                                        'selected_icon'                => ['value' => '{{social_platform_1_icon}}', 'library' => '{{social_platform_1_icon_library}}'],
                                        'icon_align'                   => 'left',
                                        'icon_indent'                  => ['unit' => 'px', 'size' => 8, 'sizes' => []],
                                        'button_text_color'            => '#FFFFFF',
                                        'background_color'             => '#EF470F',
                                        'button_background_hover_color'=> '#2D2900',
                                        'border_radius'                => ['unit' => 'px', 'top' => '50', 'right' => '50', 'bottom' => '50', 'left' => '50', 'isLinked' => true],
                                        'link'                         => ['url' => '{{social_platform_1_url}}', 'is_external' => 'on', 'nofollow' => '', 'custom_attributes' => ''],
                                    ],
                                    'elements'   => [],
                                    'isInner'    => false,
                                    'widgetType' => 'button',
                                    'elType'     => 'widget',
                                ],
                            ],
                            'isInner' => true,
                            'elType'  => 'container',
                        ],
                    ],
                    'isInner' => true,
                    'elType'  => 'container',
                ],
            ],
            'isInner' => true,
            'elType'  => 'container',
        ];
    }
}

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
    public const CONTENT_IMAGE = 'content_image';

    public static function templates(): array
    {
        return [
            self::LOCAL_SERVICE_HERO => [
                'label'       => 'Simple Local Hero',
                'description' => 'Simple Elementor container hero with one background image, H1, subheadline, and two CTA buttons.',
            ],
            self::CONTENT_IMAGE => [
                'label'       => 'Text + CTA + Right Image',
                'description' => 'Two-column content section with heading, short copy, CTA button, and an image that can be imported into client media.',
            ],
        ] + (class_exists(ElementorTemplateLibrary::class) ? ElementorTemplateLibrary::templateChoices() : []);
    }

    public static function template(string $key): ?array
    {
        switch ($key) {
            case self::LOCAL_SERVICE_HERO:
                return self::localServiceHeroTemplate();
            case self::CONTENT_IMAGE:
                return self::contentImageTemplate();
            default:
                return class_exists(ElementorTemplateLibrary::class) ? ElementorTemplateLibrary::template($key) : null;
        }
    }

    public static function templateJson(string $key): string
    {
        $template = self::template($key);
        if (!$template) {
            return '';
        }

        return (string)wp_json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function compose(array $keys): ?array
    {
        $content = [];
        $valid_keys = [];

        foreach ($keys as $key) {
            $key = sanitize_key((string)$key);
            $template = self::template($key);
            if (!$template || empty($template['content']) || !is_array($template['content'])) {
                continue;
            }

            $valid_keys[] = $key;
            foreach ($template['content'] as $section) {
                $content[] = $section;
            }
        }

        if (!$content) {
            return null;
        }

        return [
            'content'       => $content,
            'page_settings' => ['hide_title' => 'yes'],
            'version'       => '0.4',
            'title'         => count($valid_keys) > 1 ? '{{template_title}}' : (self::template($valid_keys[0])['title'] ?? '{{template_title}}'),
            'type'          => 'container',
        ];
    }

    public static function defaults(string $key): array
    {
        switch ($key) {
            case self::LOCAL_SERVICE_HERO:
                return self::heroDefaults();
            case self::CONTENT_IMAGE:
                return self::contentImageDefaults();
            default:
                return class_exists(ElementorTemplateLibrary::class) ? ElementorTemplateLibrary::defaults($key) : [];
        }
    }

    public static function defaultsFor(array $keys): array
    {
        $defaults = [];
        foreach ($keys as $key) {
            $defaults = array_merge($defaults, self::defaults((string)$key));
        }
        return $defaults;
    }

    public static function exampleVariables(string $key = self::LOCAL_SERVICE_HERO): array
    {
        if ($key === self::CONTENT_IMAGE) {
            return array_merge(self::contentImageDefaults(), [
                'content_heading'     => 'Turn Your Website Into a Lead-Generating Machine',
                'content_paragraph_1' => 'Many service businesses struggle with outdated websites that load slowly, fail to engage visitors, or do not support real growth. A weak online presence can make it harder for potential customers to find you, trust you, or take action.',
                'content_paragraph_2' => 'Whether you are starting fresh or improving an existing site, our approach is built around long-term results. We offer clear, predictable plans that support ongoing improvements, updates, and performance so your website continues to grow with your business.',
                'content_cta_text'    => 'Book a Discovery Call',
                'content_cta_url'     => '/contact/',
                'content_image_url'   => 'https://goldenwebmarketing.com/wp-content/uploads/content-section-example.jpg',
                'content_image_alt'   => 'Golden Web Marketing website strategy preview',
            ]);
        }

        return array_merge(self::heroDefaults(), self::contentImageDefaults(), [
            'template_title'            => 'Golden Web Marketing Homepage',
            'section_title'             => 'Golden Web Marketing Hero',
            'primary_keyword'           => 'Websites, SEO & PPC That Generate More Leads',
            'service'                   => 'Website Design',
            'city'                      => 'Orlando',
            'state'                     => 'FL',
            'h1'                        => 'Websites, SEO & PPC That Generate More Leads',
            'hero_subheadline'          => 'Golden Web Marketing helps local businesses grow with high-converting websites, SEO, and PPC campaigns built to generate more calls, leads, and revenue.',
            'hero_background_image_url' => 'https://goldenwebmarketing.com/wp-content/uploads/hero-example.jpg',
            'hero_background_image_alt' => 'Golden Web Marketing team working on local business marketing',
            'primary_cta_text'          => 'Get a Free Strategy Call',
            'primary_cta_url'           => '/contact/',
            'secondary_cta_text'        => 'View Our Services',
            'secondary_cta_url'         => '/services/',
            'content_heading'           => 'Turn Your Website Into a Lead-Generating Machine',
            'content_paragraph_1'       => 'Many service businesses struggle with outdated websites that load slowly, fail to engage visitors, or do not support real growth.',
            'content_paragraph_2'       => 'Whether you are starting fresh or improving an existing site, our approach is built around long-term results.',
            'content_cta_text'          => 'Book a Discovery Call',
            'content_cta_url'           => '/contact/',
            'content_image_url'         => 'https://goldenwebmarketing.com/wp-content/uploads/content-section-example.jpg',
            'content_image_alt'         => 'Golden Web Marketing website strategy preview',
            'title_tag'                 => 'Golden Web Marketing | Websites, SEO & PPC That Generate Leads',
            'meta_description'          => 'Golden Web Marketing helps local businesses grow with high-converting websites, SEO, and PPC campaigns designed to generate more calls, leads, and revenue.',
        ]);
    }

    private static function heroDefaults(): array
    {
        return [
            'section_title'                      => '',
            'template_title'                     => 'Simple Local Hero Section',
            'primary_keyword'                    => '',
            'service'                            => '',
            'city'                               => '',
            'state'                              => '',
            'h1'                                 => '',
            'hero_subheadline'                   => '',
            'hero_background_image_url'          => '',
            'hero_background_image_alt'          => '',
            'hero_overlay_image_url'             => '',
            'hero_overlay_image_alt'             => '',
            'hero_slide_1_url'                   => '',
            'hero_slide_2_url'                   => '',
            'hero_slide_3_url'                   => '',
            'primary_cta_text'                   => 'Get a Free Estimate',
            'primary_cta_url'                    => '/contact/',
            'secondary_cta_text'                 => 'View Services',
            'secondary_cta_url'                  => '/services/',
            'accent_color'                       => '#D9BE42',
            'hero_background_color'              => '#111111',
            'hero_gradient_secondary_color'      => '#2D2900',
            'hero_overlay_color'                 => 'rgba(0,0,0,0.55)',
            'hero_overlay_color_secondary'       => 'rgba(0,0,0,0.15)',
            'hero_heading_color'                 => '#FFFFFF',
            'body_font_family'                   => 'Roboto',
            'button_font_family'                 => 'Roboto',
            'hero_background_video_url'          => '',
            'hero_background_video_fallback_url' => '',
            'hero_background_video_alt'          => '',

            // Backward-compatible aliases from the first hero test.
            'hero_description'                   => '',
            'hero_background_placeholder_url'    => '',
            'cta_button_1_text'                  => '',
            'cta_button_1_url'                   => '',
            'cta_button_2_text'                  => '',
            'cta_button_2_url'                   => '',
        ];
    }

    private static function contentImageDefaults(): array
    {
        return [
            'content_section_title'  => 'Text + CTA + Right Image',
            'content_heading'        => 'Turn Your Website Into a Lead-Generating Machine',
            'content_paragraph_1'    => 'Many service businesses struggle with outdated websites that load slowly, fail to engage visitors, or do not support real growth. A weak online presence can make it harder for potential customers to find you, trust you, or take action.',
            'content_paragraph_2'    => 'Whether you are starting fresh or improving an existing site, our approach is built around long-term results. We offer clear, predictable plans that support ongoing improvements, updates, and performance so your website continues to grow with your business.',
            'content_cta_text'       => 'Book a Discovery Call',
            'content_cta_url'        => '/contact/',
            'content_image_url'      => '',
            'content_image_alt'      => '',
            'content_heading_color'  => '#111111',
            'content_text_color'     => '#222222',
            'content_background_color' => '#FFFFFF',
            'accent_color'           => '#D9BE42',
            'body_font_family'       => 'Roboto',
            'button_font_family'     => 'Poppins',
        ];
    }

    private static function localServiceHeroTemplate(): array
    {
        return [
            'content' => [
                [
                    'id'       => '610dc5e3',
                    'settings' => [
                        'flex_direction'              => 'row',
                        'flex_wrap'                   => 'wrap',
                        'flex_align_items'            => 'center',
                        'flex_justify_content'        => 'flex-start',
                        'content_position'            => 'middle',
                        'content_width'               => 'boxed',
                        'boxed_width'                 => ['unit' => 'px', 'size' => 1024, 'sizes' => []],
                        'min_height'                  => ['unit' => 'vh', 'size' => 80, 'sizes' => []],
                        'min_height_tablet'           => ['unit' => 'px', 'size' => 702, 'sizes' => []],
                        'min_height_mobile'           => ['unit' => 'vh', 'size' => 71, 'sizes' => []],
                        'padding'                     => ['unit' => 'px', 'top' => '180', 'right' => '40', 'bottom' => '160', 'left' => '40', 'isLinked' => false],
                        'padding_tablet'              => ['unit' => '%', 'top' => '0', 'right' => '0', 'bottom' => '11', 'left' => '0', 'isLinked' => false],
                        'padding_mobile'              => ['unit' => '%', 'top' => '0', 'right' => '3', 'bottom' => '0', 'left' => '3', 'isLinked' => false],
                        'margin'                      => ['unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false],
                        'z_index'                     => 90,
                        '_title'                      => '{{section_title}}',
                        'background_background'       => 'classic',
                        'background_color'            => '{{hero_background_color}}',
                        'background_color_b'          => '{{hero_gradient_secondary_color}}',
                        'background_image'            => [
                            'id'     => '',
                            'url'    => '{{hero_background_image_url}}',
                            'alt'    => '{{hero_background_image_alt}}',
                            'source' => 'library',
                            'size'   => '2048x2048',
                        ],
                        'background_position'         => 'center center',
                        'background_repeat'           => 'no-repeat',
                        'background_size'             => 'cover',
                        'background_overlay_background'=> 'classic',
                        'background_overlay_color'    => '{{hero_overlay_color}}',
                        'background_overlay_color_b'  => '{{hero_overlay_color_secondary}}',
                        'background_overlay_opacity'  => ['unit' => 'px', 'size' => 0.46, 'sizes' => []],
                        'background_overlay_image'    => [
                            'id'     => '',
                            'url'    => '{{hero_overlay_image_url}}',
                            'alt'    => '{{hero_overlay_image_alt}}',
                            'source' => 'library',
                            'size'   => '',
                        ],
                        'background_overlay_position' => 'center center',
                        'background_overlay_repeat'   => 'no-repeat',
                        'background_overlay_size'     => 'cover',
                        'background_video_link'       => '{{hero_background_video_url}}',
                        'background_video_fallback'   => [
                            'id'     => '',
                            'url'    => '{{hero_background_video_fallback_url}}',
                            'alt'    => '{{hero_background_video_alt}}',
                            'source' => 'library',
                            'size'   => '',
                        ],
                        'background_play_on_mobile'   => 'yes',
                        'background_slideshow_gallery'=> [
                            ['id' => '', 'url' => '{{hero_slide_1_url}}'],
                            ['id' => '', 'url' => '{{hero_slide_2_url}}'],
                            ['id' => '', 'url' => '{{hero_slide_3_url}}'],
                        ],
                        'background_slideshow_background_size'     => 'cover',
                        'background_slideshow_background_position' => 'center center',
                    ],
                    'elements' => [
                        self::simpleHeroContentContainer(),
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

    private static function contentImageTemplate(): array
    {
        return [
            'content' => [
                [
                    'id'       => '1272057d',
                    'settings' => [
                        'flex_direction'    => 'row',
                        'flex_wrap_tablet'  => 'wrap',
                        'flex_wrap_mobile'  => 'wrap',
                        'flex_gap'          => ['unit' => 'px', 'size' => 0, 'column' => '0', 'row' => '0'],
                        'padding'           => ['unit' => 'px', 'top' => '70', 'right' => '40', 'bottom' => '90', 'left' => '40', 'isLinked' => false],
                        'padding_tablet'    => ['unit' => 'px', 'top' => '60', 'right' => '30', 'bottom' => '70', 'left' => '30', 'isLinked' => false],
                        'padding_mobile'    => ['unit' => 'px', 'top' => '50', 'right' => '22', 'bottom' => '60', 'left' => '22', 'isLinked' => false],
                        'background_background' => 'classic',
                        'background_color'  => '{{content_background_color}}',
                        '_title'            => '{{content_section_title}}',
                    ],
                    'elements' => [
                        self::contentImageTextColumn(),
                        self::contentImageMediaColumn(),
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

    private static function simpleHeroContentContainer(): array
    {
        return [
            'id'       => '3a6d4fde',
            'settings' => [
                'content_width'       => 'full',
                'width'               => ['unit' => '%', 'size' => 100],
                'width_tablet'        => ['unit' => 'px', 'size' => 716.062],
                'flex_align_items'    => 'center',
                'flex_gap'            => ['column' => '30', 'row' => '30', 'isLinked' => true, 'unit' => 'px', 'size' => 30],
                'padding'             => ['unit' => 'px', 'top' => '0', 'right' => '50', 'bottom' => '40', 'left' => '50', 'isLinked' => false],
                'padding_mobile'      => ['unit' => 'px', 'top' => '0', 'right' => '20', 'bottom' => '0', 'left' => '20', 'isLinked' => false],
                '_flex_size'          => 'none',
                '_element_width'      => 'initial',
            ],
            'elements' => [
                [
                    'id'       => '61049e1a',
                    'settings' => [
                        'title'                       => '{{h1}}',
                        'header_size'                 => 'h1',
                        'align'                       => 'center',
                        'align_tablet'                => 'center',
                        'title_color'                 => '{{hero_heading_color}}',
                        'typography_typography'       => 'custom',
                        'typography_font_family'      => '{{body_font_family}}',
                        'typography_font_size'        => ['unit' => 'px', 'size' => 64, 'sizes' => []],
                        'typography_font_size_tablet' => ['unit' => 'px', 'size' => 46, 'sizes' => []],
                        'typography_font_size_mobile' => ['unit' => 'px', 'size' => 34, 'sizes' => []],
                        'typography_font_weight'      => '700',
                        'typography_line_height'      => ['unit' => 'em', 'size' => 1.08, 'sizes' => []],
                    ],
                    'elements'   => [],
                    'isInner'    => false,
                    'widgetType' => 'heading',
                    'elType'     => 'widget',
                ],
                [
                    'id'       => '52472081',
                    'settings' => [
                        'title'                       => '{{hero_subheadline}}',
                        'align'                       => 'center',
                        'align_tablet'                => 'center',
                        'header_size'                 => 'h5',
                        'title_color'                 => '#FFFFFF',
                        'typography_typography'       => 'custom',
                        'typography_font_family'      => '{{body_font_family}}',
                        'typography_font_size'        => ['unit' => 'px', 'size' => 25, 'sizes' => []],
                        'typography_font_size_tablet' => ['unit' => 'px', 'size' => 18, 'sizes' => []],
                        'typography_font_size_mobile' => ['unit' => 'px', 'size' => 16, 'sizes' => []],
                        'typography_font_weight'      => '500',
                        'typography_line_height'      => ['unit' => 'em', 'size' => 1.5, 'sizes' => []],
                        '_element_width'             => 'initial',
                        '_element_custom_width'      => ['unit' => '%', 'size' => 90.043],
                        '_flex_size'                 => 'none',
                    ],
                    'elements'   => [],
                    'isInner'    => false,
                    'widgetType' => 'heading',
                    'elType'     => 'widget',
                ],
                [
                    'id'       => '7b301376',
                    'settings' => [
                        'content_width'               => 'full',
                        'flex_direction'              => 'row',
                        'flex_direction_tablet'       => 'row',
                        'flex_justify_content'        => 'center',
                        'flex_justify_content_tablet' => 'center',
                        'flex_align_items'            => 'center',
                        'flex_align_items_tablet'     => 'center',
                        'flex_wrap_tablet'            => 'wrap',
                        'flex_wrap_mobile'            => 'wrap',
                        'flex_gap'                    => ['column' => '20', 'row' => '20', 'isLinked' => false, 'unit' => 'px', 'size' => 20],
                        'flex_gap_mobile'             => ['column' => '10', 'row' => '10', 'isLinked' => true, 'unit' => 'px', 'size' => 10],
                    ],
                    'elements' => [
                        self::simpleHeroButton('58dae7bd', '{{primary_cta_text}}', '{{primary_cta_url}}', true),
                        self::simpleHeroButton('14f0b0d7', '{{secondary_cta_text}}', '{{secondary_cta_url}}', false),
                    ],
                    'isInner' => true,
                    'elType'  => 'container',
                ],
            ],
            'isInner' => true,
            'elType'  => 'container',
        ];
    }

    private static function simpleHeroButton(string $id, string $text, string $url, bool $primary): array
    {
        return [
            'id'       => $id,
            'settings' => [
                'text'                         => $text,
                'align'                        => 'center',
                'button_text_color'            => $primary ? '#111111' : '#FFFFFF',
                'background_color'             => $primary ? '{{accent_color}}' : '#02010100',
                'hover_color'                  => $primary ? '#FFFFFF' : '{{accent_color}}',
                'button_background_hover_color'=> $primary ? '#02010100' : '{{accent_color}}',
                'border_border'                => 'solid',
                'border_width'                 => ['unit' => 'px', 'top' => '2', 'right' => '2', 'bottom' => '2', 'left' => '2', 'isLinked' => true],
                'border_color'                 => '{{accent_color}}',
                'border_radius'                => ['unit' => 'px', 'top' => '8', 'right' => '8', 'bottom' => '8', 'left' => '8', 'isLinked' => true],
                'text_padding'                 => ['unit' => 'px', 'top' => '18', 'right' => '44', 'bottom' => '18', 'left' => '44', 'isLinked' => false],
                'text_padding_mobile'          => ['unit' => 'px', 'top' => '15', 'right' => '30', 'bottom' => '15', 'left' => '30', 'isLinked' => false],
                'typography_typography'        => 'custom',
                'typography_font_family'       => '{{button_font_family}}',
                'typography_font_size'         => ['unit' => 'px', 'size' => 18, 'sizes' => []],
                'typography_font_size_tablet'  => ['unit' => 'px', 'size' => 14, 'sizes' => []],
                'typography_font_weight'       => '600',
                'typography_text_transform'    => $primary ? 'uppercase' : 'none',
                'typography_line_height'       => ['unit' => 'em', 'size' => 1, 'sizes' => []],
                'link'                         => ['url' => $url, 'is_external' => '', 'nofollow' => '', 'custom_attributes' => ''],
            ],
            'elements'   => [],
            'isInner'    => false,
            'widgetType' => 'button',
            'elType'     => 'widget',
        ];
    }

    private static function contentImageTextColumn(): array
    {
        return [
            'id'       => '1a664ffa',
            'settings' => [
                'flex_direction'       => 'column',
                'content_width'        => 'full',
                'width'                => ['unit' => '%', 'size' => 54.417],
                'width_tablet'         => ['unit' => '%', 'size' => 100],
                'width_mobile'         => ['unit' => '%', 'size' => 100],
                '_flex_size'           => 'none',
                '_element_width'       => 'initial',
                'flex_gap'             => ['unit' => 'px', 'size' => 18, 'column' => '18', 'row' => '18'],
            ],
            'elements' => [
                [
                    'id'       => '1fb233f2',
                    'settings' => [
                        'title'                       => '{{content_heading}}',
                        'align'                       => 'left',
                        'title_color'                 => '{{content_heading_color}}',
                        'typography_typography'       => 'custom',
                        'typography_font_family'      => '{{body_font_family}}',
                        'typography_font_size'        => ['unit' => 'px', 'size' => 44, 'sizes' => []],
                        'typography_font_size_tablet' => ['unit' => 'px', 'size' => 34, 'sizes' => []],
                        'typography_font_size_mobile' => ['unit' => 'px', 'size' => 30, 'sizes' => []],
                        'typography_font_weight'      => '700',
                        'typography_line_height'      => ['unit' => 'em', 'size' => 1.12, 'sizes' => []],
                    ],
                    'elements'   => [],
                    'isInner'    => false,
                    'widgetType' => 'heading',
                    'elType'     => 'widget',
                ],
                [
                    'id'       => '6567b5f8',
                    'settings' => [
                        'editor'                      => '<div><p>{{content_paragraph_1}}</p><p>{{content_paragraph_2}}</p></div>',
                        'align'                       => 'left',
                        'text_color'                  => '{{content_text_color}}',
                        'typography_typography'       => 'custom',
                        'typography_font_family'      => '{{body_font_family}}',
                        'typography_font_size'        => ['unit' => 'px', 'size' => 17, 'sizes' => []],
                        'typography_font_size_tablet' => ['unit' => 'px', 'size' => 15, 'sizes' => []],
                        'typography_font_weight'      => '400',
                        'typography_line_height'      => ['unit' => 'em', 'size' => 1.5, 'sizes' => []],
                        '_element_width'             => 'initial',
                        '_element_custom_width'      => ['unit' => '%', 'size' => 92, 'sizes' => []],
                    ],
                    'elements'   => [],
                    'isInner'    => false,
                    'widgetType' => 'text-editor',
                    'elType'     => 'widget',
                ],
                [
                    'id'       => '66b93fcb',
                    'settings' => [
                        'text'                         => '{{content_cta_text}}',
                        'align'                        => 'left',
                        'button_text_color'            => '#111111',
                        'background_color'             => '{{accent_color}}',
                        'hover_color'                  => '{{accent_color}}',
                        'button_background_hover_color'=> '#02010100',
                        'border_border'                => 'solid',
                        'border_width'                 => ['unit' => 'px', 'top' => '2', 'right' => '2', 'bottom' => '2', 'left' => '2', 'isLinked' => true],
                        'border_color'                 => '{{accent_color}}',
                        'border_radius'                => ['unit' => 'px', 'top' => '10', 'right' => '10', 'bottom' => '10', 'left' => '10', 'isLinked' => true],
                        'text_padding'                 => ['unit' => 'px', 'top' => '16', 'right' => '55', 'bottom' => '16', 'left' => '55', 'isLinked' => false],
                        '_margin'                      => ['unit' => 'px', 'top' => '20', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false],
                        'link'                         => ['url' => '{{content_cta_url}}', 'is_external' => '', 'nofollow' => '', 'custom_attributes' => ''],
                        'typography_typography'        => 'custom',
                        'typography_font_family'       => '{{button_font_family}}',
                        'typography_font_size'         => ['unit' => 'px', 'size' => 18, 'sizes' => []],
                        'typography_font_size_tablet'  => ['unit' => 'px', 'size' => 14, 'sizes' => []],
                        'typography_font_weight'       => '600',
                        'typography_text_transform'    => 'capitalize',
                        'typography_line_height'       => ['unit' => 'em', 'size' => 1, 'sizes' => []],
                        'selected_icon'                => ['value' => 'fas fa-arrow-right', 'library' => 'fa-solid'],
                        'icon_align'                   => 'row-reverse',
                    ],
                    'elements'   => [],
                    'isInner'    => false,
                    'widgetType' => 'button',
                    'elType'     => 'widget',
                ],
            ],
            'isInner' => true,
            'elType'  => 'container',
        ];
    }

    private static function contentImageMediaColumn(): array
    {
        return [
            'id'       => '4219a17e',
            'settings' => [
                'flex_direction'       => 'column',
                'content_width'        => 'full',
                'width'                => ['unit' => '%', 'size' => 45.583],
                'width_tablet'         => ['unit' => '%', 'size' => 100],
                'width_mobile'         => ['unit' => '%', 'size' => 100],
                'flex_justify_content' => 'center',
                'padding_mobile'       => ['unit' => 'px', 'top' => '24', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false],
            ],
            'elements' => [
                [
                    'id'       => '25ec102b',
                    'settings' => [
                        'image' => [
                            'id'     => '',
                            'url'    => '{{content_image_url}}',
                            'alt'    => '{{content_image_alt}}',
                            'source' => 'library',
                            'size'   => '',
                        ],
                        'image_border_radius' => ['unit' => 'px', 'top' => '14', 'right' => '14', 'bottom' => '14', 'left' => '14', 'isLinked' => true],
                    ],
                    'elements'   => [],
                    'isInner'    => false,
                    'widgetType' => 'image',
                    'elType'     => 'widget',
                ],
            ],
            'isInner' => true,
            'elType'  => 'container',
        ];
    }
}

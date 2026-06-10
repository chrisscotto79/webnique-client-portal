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
    public const TOP_BANNER = 'required_top_banner';
    public const LOCAL_SERVICE_HERO = 'local_service_hero';
    public const CONTENT_IMAGE = 'content_image';
    public const CONTACT_IFRAME = 'contact_iframe_section_1';
    public const CONTACT_IFRAME_2 = 'contact_iframe_section_2';
    public const CONTACT_DETAILS = 'contact_details_section';
    public const CONTACT_MAP = 'contact_map_section';
    public const GALLERY = 'gallery_section';
    public const HOME_HERO = 'home_hero_section';
    public const SERVICE_AREA = 'service_area_section';
    public const IMAGE_LEFT_TEXT_RIGHT = 'image_left_text_right_section';
    public const BLOG_POSTS = 'blog_posts_section';

    public static function templates(): array
    {
        return [
            self::TOP_BANNER => [
                'label'       => 'Required Top Banner',
                'description' => 'Required page banner automatically placed at the top of every generated page.',
                'category'    => 'Required',
                'theme'       => 'any',
                'source'      => 'built_in',
                'requires_elementor_pro' => true,
                'requirements_label' => 'Elementor Pro',
            ],
            self::LOCAL_SERVICE_HERO => [
                'label'       => 'Simple Local Hero',
                'description' => 'Simple Elementor container hero with one background image, H1, subheadline, and two CTA buttons.',
                'category'    => 'Hero',
                'theme'       => 'any',
                'source'      => 'built_in',
                'requires_elementor_pro' => true,
                'requirements_label' => 'Elementor Pro',
            ],
            self::CONTENT_IMAGE => [
                'label'       => 'Text + CTA + Right Image',
                'description' => 'Two-column content section with heading, short copy, CTA button, and an image that can be imported into client media.',
                'category'    => 'Content',
                'theme'       => 'any',
                'source'      => 'built_in',
                'requires_elementor_pro' => true,
                'requirements_label' => 'Elementor Pro',
            ],
            self::CONTACT_IFRAME => [
                'label'       => 'Contact Section Template 1',
                'description' => 'Contact details and required iframe form section for contact pages.',
                'category'    => 'Contact',
                'theme'       => 'brand',
                'source'      => 'built_in',
                'requires_elementor_pro' => true,
                'requirements_label' => 'Elementor Pro',
            ],
            self::CONTACT_IFRAME_2 => [
                'label'       => 'Contact Section Template 2',
                'description' => 'Contact form, supporting image, and social follow-up section.',
                'category'    => 'Contact Form',
                'theme'       => 'brand',
                'source'      => 'built_in',
                'requires_elementor_pro' => true,
                'requirements_label' => 'Elementor Pro + ElementsKit',
            ],
            self::CONTACT_DETAILS => [
                'label'       => 'Contact Details',
                'description' => 'Phone, business address, and email detail cards.',
                'category'    => 'Contact Details',
                'theme'       => 'brand',
                'source'      => 'built_in',
                'requires_elementor_pro' => true,
                'requirements_label' => 'Elementor Pro + ElementsKit',
            ],
            self::CONTACT_MAP => [
                'label'       => 'Contact Location Map',
                'description' => 'Google map showing the business address. Recommended for Home and Contact pages.',
                'category'    => 'Map',
                'theme'       => 'any',
                'source'      => 'built_in',
                'requires_elementor_pro' => true,
                'requirements_label' => 'Elementor Pro',
            ],
            self::GALLERY => [
                'label'       => 'Project Gallery',
                'description' => 'Visual project gallery with a short gallery introduction and closing prompt. Recommended for Home and Service pages.',
                'category'    => 'Gallery',
                'theme'       => 'dark',
                'source'      => 'built_in',
                'requires_elementor_pro' => true,
                'requirements_label' => 'Elementor Pro + RS Elements',
            ],
            self::HOME_HERO => [
                'label'       => 'Slideshow Home Hero',
                'description' => 'Home-page hero with slideshow images, primary headline, supporting copy, and two conversion CTAs.',
                'category'    => 'Hero',
                'theme'       => 'brand',
                'source'      => 'built_in',
                'requires_elementor_pro' => true,
                'requirements_label' => 'Elementor Pro',
            ],
            self::SERVICE_AREA => [
                'label'       => 'Service Areas Introduction',
                'description' => 'Local SEO section summarizing the cities and surrounding communities the business serves.',
                'category'    => 'Service Area',
                'theme'       => 'brand',
                'source'      => 'built_in',
                'requires_elementor_pro' => true,
                'requirements_label' => 'Elementor Pro',
            ],
            self::IMAGE_LEFT_TEXT_RIGHT => [
                'label'       => 'Image Left + Text Right',
                'description' => 'Supporting content section with an image, related-service heading, explanatory copy, and useful bullet points.',
                'category'    => 'Content Split',
                'theme'       => 'brand',
                'source'      => 'built_in',
                'requires_elementor_pro' => true,
                'requirements_label' => 'Elementor Pro',
            ],
            self::BLOG_POSTS => [
                'label'       => 'Blog Posts Grid',
                'description' => 'Dynamic blog archive section that displays existing WordPress posts. Use on Blog pages.',
                'category'    => 'Blog',
                'theme'       => 'brand',
                'source'      => 'built_in',
                'requires_elementor_pro' => true,
                'requirements_label' => 'Elementor Pro + HFE',
            ],
        ] + (class_exists(ElementorTemplateLibrary::class) ? ElementorTemplateLibrary::templateChoices() : []);
    }

    public static function template(string $key): ?array
    {
        switch ($key) {
            case self::TOP_BANNER:
                return self::topBannerTemplate();
            case self::LOCAL_SERVICE_HERO:
                return self::localServiceHeroTemplate();
            case self::CONTENT_IMAGE:
                return self::contentImageTemplate();
            case self::CONTACT_IFRAME:
                return self::contactIframeTemplate();
            case self::CONTACT_IFRAME_2:
                return self::contactIframeTemplate2();
            case self::CONTACT_DETAILS:
                return self::contactDetailsTemplate();
            case self::CONTACT_MAP:
                return self::contactMapTemplate();
            case self::GALLERY:
                return self::galleryTemplate();
            case self::HOME_HERO:
                return self::homeHeroTemplate();
            case self::SERVICE_AREA:
                return self::serviceAreaTemplate();
            case self::IMAGE_LEFT_TEXT_RIGHT:
                return self::imageLeftTextRightTemplate();
            case self::BLOG_POSTS:
                return self::blogPostsTemplate();
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
        $page_settings = ['hide_title' => 'yes'];
        $banner_keys = [];
        $other_keys = [];

        foreach (array_values(array_unique($keys)) as $key) {
            $key = sanitize_key((string)$key);
            $template = self::template($key);
            if ($template && self::templateProvidesTopBanner($key, $template)) {
                if (!$banner_keys) {
                    $banner_keys[] = $key;
                }
            } else {
                $other_keys[] = $key;
            }
        }
        $keys = array_merge($banner_keys, $other_keys);

        foreach ($keys as $key) {
            $key = sanitize_key((string)$key);
            $template = self::template($key);
            if (!$template || empty($template['content']) || !is_array($template['content'])) {
                continue;
            }

            $valid_keys[] = $key;
            foreach ($template['content'] as $index => $section) {
                if (
                    $index === 0
                    && self::templateProvidesTopBanner($key, $template)
                    && is_array($section)
                ) {
                    $section['settings'] = isset($section['settings']) && is_array($section['settings'])
                        ? $section['settings']
                        : [];
                    $section['settings']['_wnq_required_section'] = 'top_banner';
                }
                $content[] = $section;
            }
            if (!empty($template['page_settings']) && is_array($template['page_settings'])) {
                $page_settings = array_replace_recursive($page_settings, $template['page_settings']);
            }
        }

        if (!$content) {
            return null;
        }

        return self::applyRequiredSectionsToTemplate([
            'content'       => $content,
            'page_settings' => $page_settings,
            'version'       => '0.4',
            'title'         => count($valid_keys) > 1 ? '{{template_title}}' : (self::template($valid_keys[0])['title'] ?? '{{template_title}}'),
            'type'          => 'container',
        ], 'custom');
    }

    public static function applyRequiredSectionsToTemplate(array $template, string $page_type): array
    {
        $content = isset($template['content']) && is_array($template['content']) ? $template['content'] : [];

        if (!self::contentHasRequiredSection($content, 'top_banner') && !self::contentStartsWithBanner($content)) {
            $banner = self::topBannerTemplate();
            $content = array_merge((array)($banner['content'] ?? []), $content);
        }
        if ($page_type === 'contact' && !self::contentHasRequiredSection($content, 'contact_iframe')) {
            $contact = self::contactIframeTemplate();
            $content = array_merge($content, (array)($contact['content'] ?? []));
        }

        $template['content'] = $content;
        $template['page_settings'] = array_replace_recursive(
            ['hide_title' => 'yes'],
            isset($template['page_settings']) && is_array($template['page_settings']) ? $template['page_settings'] : []
        );

        return $template;
    }

    public static function defaults(string $key): array
    {
        switch ($key) {
            case self::TOP_BANNER:
                return self::bannerDefaults();
            case self::LOCAL_SERVICE_HERO:
                return self::heroDefaults();
            case self::CONTENT_IMAGE:
                return self::contentImageDefaults();
            case self::CONTACT_IFRAME:
                return self::contactDefaults();
            case self::CONTACT_IFRAME_2:
                return self::contactTemplate2Defaults();
            case self::CONTACT_DETAILS:
                return self::contactDetailsDefaults();
            case self::CONTACT_MAP:
                return self::contactMapDefaults();
            case self::GALLERY:
                return self::galleryDefaults();
            case self::HOME_HERO:
                return self::homeHeroDefaults();
            case self::SERVICE_AREA:
                return self::serviceAreaDefaults();
            case self::IMAGE_LEFT_TEXT_RIGHT:
                return self::imageLeftTextRightDefaults();
            case self::BLOG_POSTS:
                return [];
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

    public static function writingContextFor(array $keys): string
    {
        if (!in_array(self::TOP_BANNER, $keys, true)) {
            array_unshift($keys, self::TOP_BANNER);
        }
        $choices = self::templates();
        $sections = [];
        $position = 1;

        foreach ($keys as $key) {
            $key = sanitize_key((string)$key);
            $template = self::template($key);
            if (!$template) {
                continue;
            }

            $choice = (array)($choices[$key] ?? []);
            $label = trim((string)($choice['label'] ?? $template['title'] ?? $key));
            $category = trim((string)($choice['category'] ?? 'Custom'));
            $description = trim((string)($choice['description'] ?? ''));
            $variables = class_exists(ElementorTemplateLibrary::class)
                ? ElementorTemplateLibrary::scanVariables($template)
                : [];

            $lines = [
                sprintf('%d. %s', $position, $label !== '' ? $label : $key),
                '   Category: ' . ($category !== '' ? $category : 'Custom'),
            ];
            if ($description !== '') {
                $lines[] = '   Purpose: ' . $description;
            }
            $lines[] = '   Variables: ' . ($variables ? implode(', ', $variables) : 'None');
            $sections[] = implode("\n", $lines);
            $position++;
        }

        return $sections ? implode("\n\n", $sections) : 'No section metadata available.';
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

    private static function bannerDefaults(): array
    {
        return [
            'banner_heading'          => '',
            'banner_subheadline'      => '',
            'banner_background_color' => '#111111',
            'banner_text_color'       => '#FFFFFF',
            'accent_color'            => '#D9BE42',
        ];
    }

    private static function contactDefaults(): array
    {
        return [
            'contact_heading'      => 'Contact Us',
            'contact_intro'        => 'We are here to help. Reach out and our team will get back to you soon.',
            'phone_number'         => '',
            'business_email'       => '',
            'business_address'     => '',
            'contact_form_iframe'  => '',
            'contact_privacy_text' => 'We respect your privacy. Your information will never be shared.',
        ];
    }

    private static function contactTemplate2Defaults(): array
    {
        return array_merge(self::contactDefaults(), [
            'contact_eyebrow'     => 'Contact',
            'contact_heading'     => 'Connect with us',
            'contact_intro'       => 'Fill out the form or give us a call to request service, schedule an appointment, or get a quote.',
            'contact_image_url'   => '',
            'contact_image_alt'   => '',
            'social_heading'      => 'Follow Us:',
            'facebook_url'        => '',
        ]);
    }

    private static function contactDetailsDefaults(): array
    {
        return [
            'phone_number'     => '',
            'business_address' => '',
            'business_email'   => '',
        ];
    }

    private static function contactMapDefaults(): array
    {
        return [
            'business_address' => '',
        ];
    }

    private static function galleryDefaults(): array
    {
        return [
            'gallery_eyebrow'      => 'Our Recent Projects',
            'gallery_heading'      => 'Featured Work Gallery',
            'gallery_closing_label' => 'Looking for more projects?',
            'gallery_button_text'   => 'View All Projects',
            'gallery_button_url'    => '/projects/',
            'gallery_cta_label'     => 'Connect with us for guidance',
            'gallery_image_1_url'  => '',
            'gallery_image_2_url'  => '',
            'gallery_image_3_url'  => '',
            'gallery_image_4_url'  => '',
            'gallery_image_5_url'  => '',
            'gallery_image_6_url'  => '',
        ];
    }

    private static function homeHeroDefaults(): array
    {
        return array_merge(self::heroDefaults(), [
            'hero_highlighted_text' => '',
            'hero_slide_4_url'      => '',
        ]);
    }

    private static function serviceAreaDefaults(): array
    {
        return [
            'service_area_eyebrow' => 'Service Areas',
            'service_area_heading' => '',
            'service_area_copy'    => '',
            'service_area_url'     => '/service-areas/',
        ];
    }

    private static function imageLeftTextRightDefaults(): array
    {
        return [
            'split_eyebrow'      => '',
            'split_heading'      => '',
            'split_highlight'    => '',
            'split_content_copy' => '',
            'split_image_url'    => '',
            'split_image_alt'    => '',
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

    private static function topBannerTemplate(): array
    {
        return [
            'content' => [[
                'id'       => 'wnqbnr01',
                'settings' => [
                    '_wnq_required_section' => 'top_banner',
                    'content_width'          => 'boxed',
                    'min_height'             => ['unit' => 'px', 'size' => 240, 'sizes' => []],
                    'flex_direction'         => 'column',
                    'flex_justify_content'   => 'center',
                    'flex_align_items'       => 'center',
                    'padding'                => ['unit' => 'px', 'top' => '70', 'right' => '30', 'bottom' => '70', 'left' => '30', 'isLinked' => false],
                    'background_background'  => 'classic',
                    'background_color'       => '{{banner_background_color}}',
                ],
                'elements' => [
                    [
                        'id'       => 'wnqbnr02',
                        'settings' => [
                            'title'       => '{{banner_heading}}',
                            'header_size' => 'h2',
                            'align'       => 'center',
                            'title_color' => '{{banner_text_color}}',
                        ],
                        'elements'   => [],
                        'isInner'    => false,
                        'widgetType' => 'heading',
                        'elType'     => 'widget',
                    ],
                    [
                        'id'       => 'wnqbnr03',
                        'settings' => [
                            'editor'     => '<p>{{banner_subheadline}}</p>',
                            'align'      => 'center',
                            'text_color' => '{{banner_text_color}}',
                        ],
                        'elements'   => [],
                        'isInner'    => false,
                        'widgetType' => 'text-editor',
                        'elType'     => 'widget',
                    ],
                ],
                'isInner' => false,
                'elType'  => 'container',
            ]],
            'page_settings' => ['hide_title' => 'yes'],
            'version'       => '0.4',
            'title'         => 'Required Top Banner',
            'type'          => 'container',
        ];
    }

    private static function contactIframeTemplate(): array
    {
        $path = dirname(__DIR__, 2) . '/assets/elementor/contact-section-template-1.json';
        $raw = is_readable($path) ? file_get_contents($path) : '';
        $template = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($template) || empty($template['content']) || !is_array($template['content'])) {
            return ['content' => [], 'page_settings' => ['hide_title' => 'yes']];
        }

        $replacements = [
            'Contact Us' => '{{contact_heading}}',
            "<p>We're here to help. Reach out and we'll get back to you soon.</p>" => '<p>{{contact_intro}}</p>',
            '(555) 555-1234' => '{{phone_number}}',
            'Hello@example.com' => '{{business_email}}',
            "123 Main Street, suite 100\nAnytown, ST 12345" => '{{business_address}}',
            'iframe' => '{{contact_form_iframe}}',
            '<p>We resect your privacy. Your information will never be shared.</p>' => '<p>{{contact_privacy_text}}</p>',
        ];
        $template = self::replaceExactValues($template, $replacements);
        if (isset($template['content'][0]['settings']) && is_array($template['content'][0]['settings'])) {
            $template['content'][0]['settings']['_wnq_required_section'] = 'contact_iframe';
        }
        $template['title'] = 'Contact Section Template 1';

        return $template;
    }

    private static function contactIframeTemplate2(): array
    {
        $template = self::assetTemplate('contact-section-template-2.json', [
            'Contact' => '{{contact_eyebrow}}',
            "Connect with us\n" => '{{contact_heading}}',
            '<p data-start="762" data-end="938">Fill out the form below or give us a call to request service, schedule an inspection, or get a quote. We respond quickly and provide honest, dependable solutions you can trust.</p>' => '<p>{{contact_intro}}</p>',
            'https://parrishwelldrillingfl.com/wp-content/uploads/2026/01/608313660_122259822494084097_5054794541996255453_n.jpg' => '{{contact_image_url}}',
            "Follow Us:\n" => '{{social_heading}}',
            'https://www.facebook.com/p/Parrish-Well-Drilling-61552522930790/' => '{{facebook_url}}',
        ], 'contact_form_2');
        $template = self::replaceWidgetSetting($template, 'html', 'html', '{{contact_form_iframe}}');
        if (isset($template['content'][0]['settings']) && is_array($template['content'][0]['settings'])) {
            $template['content'][0]['settings']['_wnq_required_section'] = 'contact_iframe';
        }

        return $template;
    }

    private static function contactDetailsTemplate(): array
    {
        return self::assetTemplate('contact-details-section.json', [
            '+1 941-378-4061' => '{{phone_number}}',
            '941-378-4061' => '{{phone_number}}',
            '7401 Rim Rd, Sarasota, FL, United States, Florida' => '{{business_address}}',
            'Office@parrishwell.net' => '{{business_email}}',
            'Office%40parrishwell.net' => '{{business_email}}',
        ], 'contact_details');
    }

    private static function contactMapTemplate(): array
    {
        return [
            'content' => [[
                'id'       => 'wnqmap01',
                'settings' => [
                    '_wnq_section_role' => 'contact_map',
                    'flex_direction'    => 'column',
                    'content_width'     => 'full',
                    'padding'           => ['unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => false],
                ],
                'elements' => [[
                    'id'       => 'wnqmap02',
                    'settings' => [
                        'address' => '{{business_address}}',
                        'height'  => ['unit' => 'px', 'size' => 532, 'sizes' => []],
                    ],
                    'elements'   => [],
                    'isInner'    => false,
                    'widgetType' => 'google_maps',
                    'elType'     => 'widget',
                ]],
                'isInner' => false,
                'elType'  => 'container',
            ]],
            'page_settings' => ['hide_title' => 'yes'],
            'version'       => '0.4',
            'title'         => 'Contact Maps Location',
            'type'          => 'container',
        ];
    }

    private static function galleryTemplate(): array
    {
        return self::assetTemplate('gallery-section.json', [
            'Our Recent Projects' => '{{gallery_eyebrow}}',
            'Feature Work Gallery' => '{{gallery_heading}}',
            'Looking for more projects?' => '{{gallery_closing_label}}',
            'View All Projects' => '{{gallery_button_text}}',
            '/projects' => '{{gallery_button_url}}',
            'Connect with us for guidance' => '{{gallery_cta_label}}',
            'https://snshauling.com/wp-content/uploads/2026/02/1000022739-high.webp' => '{{gallery_image_1_url}}',
            'https://snshauling.com/wp-content/uploads/2026/02/1000022967-high.webp' => '{{gallery_image_2_url}}',
            'https://snshauling.com/wp-content/uploads/2026/02/1000022988-high.webp' => '{{gallery_image_3_url}}',
            'https://snshauling.com/wp-content/uploads/2026/02/1000022961-high.webp' => '{{gallery_image_4_url}}',
            'https://snshauling.com/wp-content/uploads/2026/02/1000022974-high.webp' => '{{gallery_image_5_url}}',
            'https://snshauling.com/wp-content/uploads/2026/02/1000022965-high.webp' => '{{gallery_image_6_url}}',
        ], 'gallery');
    }

    private static function homeHeroTemplate(): array
    {
        return self::assetTemplate('home-hero-section.json', [
            'Sheds, Carports & Metal Buildings in' => '{{h1}}',
            ' Southwest Florida' => '{{hero_highlighted_text}}',
            'Custom-built and in-stock sheds, carports, and metal buildings with flexible rent-to-own options. Serving Arcadia, Port Charlotte, North Port, Sarasota, Fort Myers, and surrounding areas.' => '{{hero_subheadline}}',
            'Get Pricing' => '{{primary_cta_text}}',
            '/contact/' => '{{primary_cta_url}}',
            'Call for Avalibailtiy' => '{{secondary_cta_text}}',
            '[elementor-tag id="614408f" name="contact-url" settings="%7B%22link_type%22%3A%22tel%22%2C%22tel_number%22%3A%229413915034%22%7D"]' => 'tel:{{phone_number}}',
            'https://kingsheds769.com/wp-content/uploads/2026/04/a2-1.webp' => '{{hero_slide_1_url}}',
            'https://kingsheds769.com/wp-content/uploads/2026/04/v1.webp' => '{{hero_slide_2_url}}',
            'https://kingsheds769.com/wp-content/uploads/2026/04/c7.jpg' => '{{hero_slide_3_url}}',
            'https://kingsheds769.com/wp-content/uploads/2026/04/mq7.jpg' => '{{hero_slide_4_url}}',
        ], 'top_banner');
    }

    private static function serviceAreaTemplate(): array
    {
        return self::assetTemplate('service-area-section.json', [
            'SERVICE AREAS' => '{{service_area_eyebrow}}',
            '/about/' => '{{service_area_url}}',
            'Serving Southwest Florida' => '{{service_area_heading}}',
            'King Sheds proudly serves customers throughout Southwest Florida, providing high-quality sheds, carports, and metal buildings to both homeowners and businesses. Our service area includes Arcadia, Port Charlotte, North Port, Sarasota, Fort Myers, and Cape Coral, along with surrounding communities.' => '{{service_area_copy}}',
        ], 'service_area');
    }

    private static function imageLeftTextRightTemplate(): array
    {
        return self::assetTemplate('image-left-text-right-section.json', [
            'Vinyl Shed Permits' => '{{split_eyebrow}}',
            'Nearby Vinyl Shed ' => '{{split_heading}}',
            'Services' => '{{split_highlight}}',
            '<p>In addition to vinyl sheds, King Sheds also offers a range of related services, including aluminum sheds, barn sheds, and metal-framed sheds.</p><ul><li>Aluminum sheds: Perfect for homeowners who want a lightweight and durable storage solution.</li><li>Barn sheds: Ideal for homeowners who need a larger storage space for equipment and supplies.</li><li>Metal-framed sheds: Durable and resistant to harsh weather conditions.</li></ul>' => '{{split_content_copy}}',
            'https://kingsheds769.com/wp-content/uploads/2026/04/The-Carport-Company-21.jpg' => '{{split_image_url}}',
        ], 'content_split');
    }

    private static function blogPostsTemplate(): array
    {
        return self::assetTemplate('blog-posts-section.json', [], 'blog_posts');
    }

    private static function assetTemplate(string $filename, array $replacements, string $section_role): array
    {
        $path = dirname(__DIR__, 2) . '/assets/elementor/' . $filename;
        $raw = is_readable($path) ? file_get_contents($path) : '';
        $template = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($template) || empty($template['content']) || !is_array($template['content'])) {
            return ['content' => [], 'page_settings' => ['hide_title' => 'yes']];
        }

        $template = self::replaceTextValues($template, $replacements);
        $template = self::clearPlaceholderImageIds($template);
        if (isset($template['content'][0]['settings']) && is_array($template['content'][0]['settings'])) {
            $template['content'][0]['settings']['_wnq_section_role'] = $section_role;
        }

        return $template;
    }

    private static function replaceTextValues($value, array $replacements)
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::replaceTextValues($child, $replacements);
            }
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }

        return str_replace(array_keys($replacements), array_values($replacements), $value);
    }

    private static function clearPlaceholderImageIds($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (
            isset($value['url'])
            && is_string($value['url'])
            && strpos($value['url'], '{{') !== false
        ) {
            if (array_key_exists('id', $value)) {
                $value['id'] = '';
            }
            if (
                array_key_exists('alt', $value)
                && trim((string)$value['alt']) === ''
                && preg_match('/\{\{\s*([^}]+_url)\s*\}\}/', $value['url'], $matches)
            ) {
                $value['alt'] = '{{' . preg_replace('/_url$/', '_alt', (string)$matches[1]) . '}}';
            }
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::clearPlaceholderImageIds($child);
            }
        }

        return $value;
    }

    private static function replaceWidgetSetting($value, string $widget_type, string $setting, string $replacement)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (($value['widgetType'] ?? '') === $widget_type && isset($value['settings']) && is_array($value['settings'])) {
            $value['settings'][$setting] = $replacement;
        }
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::replaceWidgetSetting($child, $widget_type, $setting, $replacement);
            }
        }

        return $value;
    }

    private static function replaceExactValues($value, array $replacements)
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::replaceExactValues($child, $replacements);
            }
            return $value;
        }

        return is_string($value) && array_key_exists($value, $replacements) ? $replacements[$value] : $value;
    }

    private static function contentHasRequiredSection(array $content, string $required_section): bool
    {
        foreach ($content as $section) {
            if (
                is_array($section)
                && isset($section['settings']['_wnq_required_section'])
                && $section['settings']['_wnq_required_section'] === $required_section
            ) {
                return true;
            }
        }

        return false;
    }

    private static function contentStartsWithBanner(array $content): bool
    {
        $first = isset($content[0]) && is_array($content[0]) ? $content[0] : [];
        $settings = isset($first['settings']) && is_array($first['settings']) ? $first['settings'] : [];
        $title = strtolower((string)($settings['_title'] ?? ''));

        return strpos($title, 'hero') !== false || strpos($title, 'banner') !== false;
    }

    private static function templateProvidesTopBanner(string $key, array $template): bool
    {
        if (in_array($key, [self::TOP_BANNER, self::LOCAL_SERVICE_HERO], true)) {
            return true;
        }

        $choices = self::templates();
        $choice = (array)($choices[$key] ?? []);
        $text = strtolower(implode(' ', [
            (string)($choice['category'] ?? ''),
            (string)($choice['label'] ?? ''),
            (string)($choice['description'] ?? ''),
            (string)($template['title'] ?? ''),
        ]));

        return strpos($text, 'hero') !== false || strpos($text, 'banner') !== false;
    }

    private static function localServiceHeroTemplate(): array
    {
        return [
            'content' => [
                [
                    'id'       => '610dc5e3',
                    'settings' => [
                        '_wnq_required_section'       => 'top_banner',
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

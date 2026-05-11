<?php
/**
 * AI Elementor Page Builder Admin
 *
 * MVP admin screen for generating editable Elementor draft pages from a
 * reusable Elementor JSON template and a JSON variable payload.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Admin;

use WNQ\Models\Client;
use WNQ\Models\SEOHub;
use WNQ\Services\AIElementorPageBuilder;

if (!defined('ABSPATH')) {
    exit;
}

final class AIElementorPageBuilderAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenuPage'], 22);
        add_action('admin_post_wnq_ai_elementor_generate', [self::class, 'handleGenerate']);
    }

    public static function addMenuPage(): void
    {
        $cap = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';
        add_submenu_page(
            'wnq-seo-hub',
            'AI Elementor Page Builder',
            'AI Elementor Builder',
            $cap,
            'wnq-seo-hub-ai-elementor',
            [self::class, 'renderPage']
        );
    }

    public static function renderPage(): void
    {
        self::checkCap();
        self::renderHeader('AI Elementor Page Builder');

        $created = isset($_GET['created']) ? absint($_GET['created']) : 0;
        $error = isset($_GET['error']) ? sanitize_text_field(wp_unslash($_GET['error'])) : '';
        $result = $created ? get_transient(self::resultTransientKey()) : false;
        if (is_array($result)) {
            delete_transient(self::resultTransientKey());
        }

        if ($created && is_array($result)) {
            echo '<div class="wnq-hub-notice success"><p><strong>Draft created on:</strong> ' . esc_html($result['site_url'] ?? 'Client site') . '</p>';
            echo '<p style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;">';
            if (!empty($result['elementor_url'])) {
                echo '<a class="wnq-btn wnq-btn-primary" target="_blank" rel="noopener" href="' . esc_url($result['elementor_url']) . '">Edit Draft</a>';
            }
            if (!empty($result['preview_url'])) {
                echo '<a class="wnq-btn" target="_blank" rel="noopener" href="' . esc_url($result['preview_url']) . '">Preview</a>';
            }
            if (!empty($result['pages_url'])) {
                echo '<a class="wnq-btn" target="_blank" rel="noopener" href="' . esc_url($result['pages_url']) . '">Open Pages</a>';
            }
            echo '</p></div>';
        }

        if ($error !== '') {
            echo '<div class="wnq-hub-notice error"><p>' . esc_html($error) . '</p></div>';
        }

        ?>
<div class="wnq-hub-section">
  <div class="wnq-hub-section-header">
    <div>
      <h2>Generate Editable Elementor Draft</h2>
      <p>Paste or upload an Elementor JSON export, then provide a JSON payload of variables. The page is always created as a draft.</p>
    </div>
  </div>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="wnq-ai-elementor-form">
    <?php wp_nonce_field('wnq_ai_elementor_generate'); ?>
    <input type="hidden" name="action" value="wnq_ai_elementor_generate">

    <?php $agents = self::connectedAgents(); ?>
    <div class="wnq-ai-elementor-target">
      <label for="wnq_agent_key_id"><strong>Select Client / WordPress Site</strong></label>
      <select id="wnq_agent_key_id" name="agent_key_id" required>
        <option value="">Choose a connected client site...</option>
        <?php foreach ($agents as $agent): ?>
          <?php
            $site_label = ($agent['site_name'] ?? '') ?: parse_url($agent['site_url'] ?? '', PHP_URL_HOST) ?: ($agent['site_url'] ?? '');
            $label = trim(($agent['client_label'] ?? '') . ' - ' . $site_label);
            $meta = [];
            if (!empty($agent['plugin_version'])) {
                $meta[] = 'Agent ' . $agent['plugin_version'];
            }
            if (!empty($agent['last_ping'])) {
                $meta[] = 'Last ping ' . $agent['last_ping'];
            }
          ?>
          <option value="<?php echo (int)$agent['id']; ?>">
            <?php echo esc_html($label . ($meta ? ' (' . implode(', ', $meta) . ')' : '')); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (empty($agents)): ?>
        <p class="description">No active connected client sites were found. Add one under <a href="<?php echo esc_url(admin_url('admin.php?page=wnq-seo-hub-api')); ?>">SEO OS API Management</a>.</p>
      <?php else: ?>
        <p class="description">This controls which client WordPress application receives the draft. The API key stays server-side.</p>
      <?php endif; ?>
    </div>

    <div class="wnq-ai-elementor-grid">
      <div class="wnq-ai-elementor-field">
        <label for="wnq_elementor_template"><strong>Elementor JSON Template</strong></label>
        <p class="description">Use an exported Elementor template. If a file is uploaded, it overrides the pasted text.</p>
        <input type="file" name="elementor_template_file" accept=".json,application/json">
        <textarea id="wnq_elementor_template" name="elementor_template" rows="18" spellcheck="false" placeholder='{"content":[...],"page_settings":{"hide_title":"yes"}}'></textarea>
      </div>

      <div class="wnq-ai-elementor-field">
        <label for="wnq_variables_json"><strong>JSON Variable Payload</strong></label>
        <p class="description">Supported placeholders include primary_keyword, service, city, state, h1, cta_title, cta_text, title_tag, and meta_description.</p>
        <input type="file" name="variables_json_file" accept=".json,application/json">
        <textarea id="wnq_variables_json" name="variables_json" rows="18" spellcheck="false" placeholder='{"service":"Land Clearing","city":"Lakeland"}'></textarea>
      </div>
    </div>

    <div class="wnq-ai-elementor-options">
      <label>
        <strong>Optional Page Title Override</strong>
        <input type="text" name="post_title" placeholder="Leave blank to use h1 or primary keyword">
      </label>
      <label>
        <strong>Optional Featured Image ID</strong>
        <input type="number" min="1" step="1" name="featured_image_id" placeholder="Client site WordPress attachment ID">
      </label>
    </div>

    <p>
      <button type="submit" class="wnq-btn wnq-btn-primary">Generate Draft Page</button>
    </p>
  </form>
</div>

<div class="wnq-hub-section">
  <h2>Examples</h2>
  <div class="wnq-ai-elementor-grid">
    <details open>
      <summary><strong>Example Elementor JSON Template</strong></summary>
      <pre><?php echo esc_html(wp_json_encode(self::exampleTemplate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
    </details>
    <details open>
      <summary><strong>Example JSON Variable Payload</strong></summary>
      <pre><?php echo esc_html(wp_json_encode(self::exampleVariables(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
    </details>
  </div>
</div>

<style>
  .wnq-ai-elementor-grid {
    display: grid;
    gap: 18px;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  }
  .wnq-ai-elementor-field textarea,
  .wnq-ai-elementor-options input,
  .wnq-ai-elementor-target select {
    width: 100%;
  }
  .wnq-ai-elementor-target {
    margin-bottom: 20px;
  }
  .wnq-ai-elementor-target select {
    max-width: 760px;
    margin-top: 8px;
  }
  .wnq-ai-elementor-field textarea {
    margin-top: 10px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 13px;
    line-height: 1.45;
  }
  .wnq-ai-elementor-field input[type="file"] {
    display: block;
    margin-top: 8px;
  }
  .wnq-ai-elementor-options {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    margin-top: 18px;
  }
  .wnq-ai-elementor-options label {
    display: block;
  }
  .wnq-ai-elementor-options input {
    margin-top: 8px;
  }
  .wnq-ai-elementor-grid pre {
    max-height: 420px;
    overflow: auto;
    padding: 16px;
    border: 1px solid #d7dde8;
    border-radius: 8px;
    background: #f8fafc;
    white-space: pre-wrap;
  }
</style>
        <?php
        self::renderFooter();
    }

    public static function handleGenerate(): void
    {
        self::checkCap();
        check_admin_referer('wnq_ai_elementor_generate');

        $agent_key_id = absint($_POST['agent_key_id'] ?? 0);
        $template_json = self::readTextInputOrFile('elementor_template', 'elementor_template_file');
        $variables_json = self::readTextInputOrFile('variables_json', 'variables_json_file');
        $variables = json_decode(trim($variables_json), true);

        if (!is_array($variables)) {
            self::redirectWithError('Invalid variable JSON: ' . json_last_error_msg());
        }

        $result = AIElementorPageBuilder::generateRemoteDraft($agent_key_id, $template_json, $variables, [
            'post_title'        => sanitize_text_field(wp_unslash($_POST['post_title'] ?? '')),
            'featured_image_id' => absint($_POST['featured_image_id'] ?? 0),
        ]);

        if (empty($result['success'])) {
            self::redirectWithError((string)($result['message'] ?? 'Draft generation failed.'));
        }

        set_transient(self::resultTransientKey(), $result, 10 * MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg([
            'page'    => 'wnq-seo-hub-ai-elementor',
            'created' => 1,
        ], admin_url('admin.php')));
        exit;
    }

    private static function readTextInputOrFile(string $text_field, string $file_field): string
    {
        if (
            !empty($_FILES[$file_field]['tmp_name'])
            && (int)($_FILES[$file_field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_uploaded_file($_FILES[$file_field]['tmp_name'])
        ) {
            $file = file_get_contents($_FILES[$file_field]['tmp_name']);
            if (is_string($file) && trim($file) !== '') {
                return $file;
            }
        }

        return (string)wp_unslash($_POST[$text_field] ?? '');
    }

    private static function redirectWithError(string $message): void
    {
        wp_safe_redirect(add_query_arg([
            'page'  => 'wnq-seo-hub-ai-elementor',
            'error' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    private static function renderHeader(string $title): void
    {
        $current = sanitize_text_field($_GET['page'] ?? 'wnq-seo-hub-ai-elementor');
        $nav_items = [
            'wnq-seo-hub'              => 'Dashboard',
            'wnq-seo-hub-clients'      => 'Clients',
            'wnq-seo-hub-keywords'     => 'Keywords',
            'wnq-seo-hub-content'      => 'Service City Pages',
            'wnq-seo-hub-reports'      => 'Reports',
            'wnq-seo-hub-blog'         => 'Blog Scheduler',
            'wnq-seo-hub-ai-elementor' => 'AI Elementor Builder',
            'wnq-seo-hub-api'          => 'API',
            'wnq-seo-hub-settings'     => 'Settings',
        ];

        echo '<div class="wrap wnq-hub-wrap">';
        echo '<div class="wnq-hub-masthead">';
        echo '<div class="wnq-hub-logo">Golden Web Marketing<span>SEO OS</span></div>';
        echo '<nav class="wnq-hub-nav">';
        foreach ($nav_items as $slug => $label) {
            $class = $current === $slug ? 'active' : '';
            echo '<a class="' . esc_attr($class) . '" href="' . esc_url(admin_url('admin.php?page=' . $slug)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav></div>';
        echo '<h1 class="wnq-hub-page-title">' . esc_html($title) . '</h1>';
    }

    private static function renderFooter(): void
    {
        echo '</div>';
    }

    private static function checkCap(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }
    }

    private static function resultTransientKey(): string
    {
        return 'wnq_ai_elementor_result_' . get_current_user_id();
    }

    private static function connectedAgents(): array
    {
        $client_labels = [];
        foreach (Client::getAll() as $client) {
            $client_id = (string)($client['client_id'] ?? '');
            if ($client_id === '') {
                continue;
            }
            $client_labels[$client_id] = $client['company'] ?: $client['name'] ?: $client_id;
        }

        $agents = [];
        foreach (SEOHub::getAllAgentKeys() as $agent) {
            if (($agent['status'] ?? '') !== 'active') {
                continue;
            }
            $client_id = (string)($agent['client_id'] ?? '');
            $agent['client_label'] = $client_labels[$client_id] ?? $client_id;
            $agents[] = $agent;
        }

        usort($agents, static function ($a, $b) {
            return strcasecmp(
                (string)($a['client_label'] ?? '') . (string)($a['site_url'] ?? ''),
                (string)($b['client_label'] ?? '') . (string)($b['site_url'] ?? '')
            );
        });

        return $agents;
    }

    private static function exampleVariables(): array
    {
        return [
            'primary_keyword'  => 'Land Clearing in Lakeland FL',
            'service'          => 'Land Clearing',
            'city'             => 'Lakeland',
            'state'            => 'FL',
            'title_tag'        => 'Land Clearing in Lakeland FL | Free Estimates',
            'meta_description' => 'Need land clearing in Lakeland FL? Get fast and affordable land clearing services.',
            'h1'               => 'Land Clearing in Lakeland FL',
            'cta_title'        => 'Get a Free Land Clearing Estimate',
            'cta_text'         => 'Call now for fast land clearing services in Lakeland FL.',
        ];
    }

    private static function exampleTemplate(): array
    {
        return [
            'content' => [
                [
                    'id'       => 'hero001',
                    'elType'   => 'container',
                    'settings' => [
                        'flex_direction' => 'column',
                        'padding' => [
                            'unit' => 'px',
                            'top' => '72',
                            'right' => '40',
                            'bottom' => '72',
                            'left' => '40',
                            'isLinked' => false,
                        ],
                    ],
                    'elements' => [
                        [
                            'id' => 'heading1',
                            'elType' => 'widget',
                            'widgetType' => 'heading',
                            'settings' => [
                                'title' => '{{h1}}',
                                'header_size' => 'h1',
                            ],
                            'elements' => [],
                        ],
                        [
                            'id' => 'intro001',
                            'elType' => 'widget',
                            'widgetType' => 'text-editor',
                            'settings' => [
                                'editor' => '<p><strong>{{primary_keyword}}</strong> from a trusted local team. {{cta_text}}</p>',
                            ],
                            'elements' => [],
                        ],
                    ],
                ],
                [
                    'id' => 'cta001',
                    'elType' => 'container',
                    'settings' => [
                        'flex_direction' => 'column',
                        'padding' => [
                            'unit' => 'px',
                            'top' => '48',
                            'right' => '40',
                            'bottom' => '48',
                            'left' => '40',
                            'isLinked' => false,
                        ],
                    ],
                    'elements' => [
                        [
                            'id' => 'ctahead1',
                            'elType' => 'widget',
                            'widgetType' => 'heading',
                            'settings' => [
                                'title' => '{{cta_title}}',
                                'header_size' => 'h2',
                            ],
                            'elements' => [],
                        ],
                        [
                            'id' => 'ctatext1',
                            'elType' => 'widget',
                            'widgetType' => 'text-editor',
                            'settings' => [
                                'editor' => '<p>{{service}} services available in {{city}}, {{state}}.</p>',
                            ],
                            'elements' => [],
                        ],
                    ],
                ],
            ],
            'page_settings' => [
                'hide_title' => 'yes',
            ],
            'version' => '0.4',
            'title' => 'Service City Template',
            'type' => 'page',
        ];
    }
}

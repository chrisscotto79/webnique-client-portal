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
use WNQ\Services\AIEngine;
use WNQ\Services\ElementorSectionLibrary;
use WNQ\Services\ElementorTemplateLibrary;

if (!defined('ABSPATH')) {
    exit;
}

final class AIElementorPageBuilderAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addMenuPage'], 22);
        add_action('admin_post_wnq_ai_elementor_generate', [self::class, 'handleGenerate']);
        add_action('admin_post_wnq_ai_elementor_save_template', [self::class, 'handleSaveTemplate']);
        add_action('admin_post_wnq_ai_elementor_delete_template', [self::class, 'handleDeleteTemplate']);
        add_action('admin_post_wnq_ai_elementor_generate_variables', [self::class, 'handleGenerateVariables']);
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
        $notice = isset($_GET['notice']) ? sanitize_text_field(wp_unslash($_GET['notice'])) : '';
        $section_templates = ElementorSectionLibrary::templates();
        $result = $created ? get_transient(self::resultTransientKey()) : false;
        $ai_payload = $notice === 'ai_payload' ? get_transient(self::aiPayloadTransientKey()) : false;
        if (is_array($result)) {
            delete_transient(self::resultTransientKey());
        }
        if (is_array($ai_payload)) {
            delete_transient(self::aiPayloadTransientKey());
        }

        if ($created && is_array($result)) {
            echo '<div class="wnq-hub-notice success"><p><strong>Draft created on:</strong> ' . esc_html($result['site_url'] ?? 'Client site') . '</p>';
            if (isset($result['images_imported'])) {
                echo '<p><strong>Images imported to client media:</strong> ' . esc_html((string)absint($result['images_imported'])) . '</p>';
            }
            if (!empty($result['image_import_errors']) && is_array($result['image_import_errors'])) {
                echo '<p><strong>Image import warnings:</strong></p><ul style="margin-left:18px;">';
                foreach ($result['image_import_errors'] as $url => $warning) {
                    $display_url = (string)$url;
                    if (strlen($display_url) > 180) {
                        $display_url = substr($display_url, 0, 180) . '...';
                    }
                    echo '<li><code>' . esc_html($display_url) . '</code>: ' . esc_html((string)$warning) . '</li>';
                }
                echo '</ul>';
            }
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
        if ($notice === 'template_saved') {
            echo '<div class="wnq-hub-notice success"><p>Template saved to the library.</p></div>';
        } elseif ($notice === 'template_deleted') {
            echo '<div class="wnq-hub-notice success"><p>Template deleted from the library.</p></div>';
        }
        if (is_array($ai_payload)) {
            echo '<div class="wnq-hub-notice success"><p><strong>AI variable payload generated.</strong> Copy this into the JSON Variable Payload box, review it, then generate the draft.</p>';
            echo '<textarea class="wnq-ai-generated-payload" rows="18" readonly spellcheck="false">' . esc_textarea((string)($ai_payload['json'] ?? '')) . '</textarea>';
            echo '</div>';
        }

        $saved_templates = ElementorTemplateLibrary::all();
        $agents = self::connectedAgents();
        $saved_template_count = count($saved_templates);
        $connected_site_count = count($agents);
        $available_template_count = count($section_templates);
        $active_tab = 'draft';
        if (in_array($notice, ['template_saved', 'template_deleted'], true)) {
            $active_tab = 'library';
        } elseif ($notice === 'ai_payload') {
            $active_tab = 'payload';
        }

        ?>
<div class="wnq-ai-builder-page" data-active-tab="<?php echo esc_attr($active_tab); ?>">
  <div class="wnq-ai-builder-hero">
    <div class="wnq-ai-builder-hero-copy">
      <span class="wnq-ai-eyebrow">Golden Web Marketing AI Builder</span>
      <h2>Build editable Elementor drafts from reusable sections.</h2>
      <p>Use the tabs below to upload templates, generate a clean variable payload, and create one editable draft page on the selected client WordPress site.</p>
    </div>
    <div class="wnq-ai-builder-stats">
      <div>
        <strong><?php echo esc_html((string)$connected_site_count); ?></strong>
        <span>Connected Sites</span>
      </div>
      <div>
        <strong><?php echo esc_html((string)$available_template_count); ?></strong>
        <span>Templates Ready</span>
      </div>
      <div>
        <strong><?php echo esc_html((string)$saved_template_count); ?></strong>
        <span>Saved Uploads</span>
      </div>
    </div>
  </div>

  <div class="wnq-ai-tab-shell">
    <div class="wnq-ai-tabs" role="tablist" aria-label="AI Elementor Builder workflow">
      <button type="button" class="wnq-ai-tab <?php echo $active_tab === 'library' ? 'is-active' : ''; ?>" data-wnq-ai-tab="library" role="tab" aria-selected="<?php echo $active_tab === 'library' ? 'true' : 'false'; ?>" aria-controls="wnq-ai-tab-library">1. Template Library</button>
      <button type="button" class="wnq-ai-tab <?php echo $active_tab === 'payload' ? 'is-active' : ''; ?>" data-wnq-ai-tab="payload" role="tab" aria-selected="<?php echo $active_tab === 'payload' ? 'true' : 'false'; ?>" aria-controls="wnq-ai-tab-payload">2. AI Payload</button>
      <button type="button" class="wnq-ai-tab <?php echo $active_tab === 'draft' ? 'is-active' : ''; ?>" data-wnq-ai-tab="draft" role="tab" aria-selected="<?php echo $active_tab === 'draft' ? 'true' : 'false'; ?>" aria-controls="wnq-ai-tab-draft">3. Draft Builder</button>
    </div>

    <div class="wnq-ai-tab-panels">
      <section class="wnq-ai-tab-panel <?php echo $active_tab === 'library' ? 'is-active' : ''; ?>" id="wnq-ai-tab-library" data-wnq-ai-panel="library" role="tabpanel" <?php echo $active_tab === 'library' ? '' : 'hidden'; ?>>
        <div class="wnq-hub-section wnq-ai-card" id="wnq-template-library">
  <div class="wnq-hub-section-header">
    <div>
      <h2><span class="wnq-ai-step">1</span>Template Library</h2>
      <p>Upload reusable Elementor JSON sections or pages. The software scans placeholders automatically and adds the saved template to the builder.</p>
    </div>
  </div>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="wnq-ai-template-form">
    <?php wp_nonce_field('wnq_ai_elementor_save_template'); ?>
    <input type="hidden" name="action" value="wnq_ai_elementor_save_template">

    <div class="wnq-ai-template-meta">
      <label>
        <strong>Template Name</strong>
        <input type="text" name="template_name" placeholder="Dark Hero - Local Service" required>
      </label>
      <label>
        <strong>Category</strong>
        <input type="text" name="template_category" placeholder="Hero, CTA, FAQ, Process">
      </label>
      <label>
        <strong>Theme</strong>
        <select name="template_theme">
          <option value="any">Any</option>
          <option value="light">Light</option>
          <option value="dark">Dark</option>
          <option value="brand">Brand Specific</option>
        </select>
      </label>
    </div>

    <label class="wnq-ai-template-description">
      <strong>Description</strong>
      <input type="text" name="template_description" placeholder="Short note about when to use this section">
    </label>

    <div class="wnq-ai-elementor-grid">
      <div class="wnq-ai-elementor-field">
        <label><strong>Upload Elementor JSON</strong></label>
        <input type="file" name="library_template_file" accept=".json,application/json">
        <p class="description">A file upload overrides pasted JSON.</p>
      </div>
      <div class="wnq-ai-elementor-field">
        <label for="wnq_library_template_json"><strong>Or Paste Elementor JSON</strong></label>
        <textarea id="wnq_library_template_json" name="library_template_json" rows="8" spellcheck="false" placeholder='{"content":[...],"page_settings":{"hide_title":"yes"}}'></textarea>
      </div>
    </div>

    <p><button type="submit" class="wnq-btn wnq-btn-primary">Save Template to Library</button></p>
  </form>

  <?php if ($saved_templates): ?>
    <div class="wnq-ai-template-library-list">
      <?php foreach ($saved_templates as $key => $template): ?>
        <div class="wnq-ai-template-card">
          <div>
            <strong><?php echo esc_html((string)($template['name'] ?? $key)); ?></strong>
            <span><?php echo esc_html((string)($template['category'] ?? 'Custom')); ?> / <?php echo esc_html(ucfirst((string)($template['theme'] ?? 'any'))); ?></span>
            <?php if (!empty($template['description'])): ?>
              <p><?php echo esc_html((string)$template['description']); ?></p>
            <?php endif; ?>
            <?php if (!empty($template['variables'])): ?>
              <div class="wnq-ai-variable-chips">
                <?php foreach ((array)$template['variables'] as $variable): ?>
                  <code>{{<?php echo esc_html((string)$variable); ?>}}</code>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete this saved template?');">
            <?php wp_nonce_field('wnq_ai_elementor_delete_template_' . $key); ?>
            <input type="hidden" name="action" value="wnq_ai_elementor_delete_template">
            <input type="hidden" name="template_key" value="<?php echo esc_attr((string)$key); ?>">
            <button type="submit" class="button">Delete</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="wnq-ai-empty-state">
      <strong>No custom templates saved yet.</strong>
      <span>Upload your first Elementor JSON section to start building a reusable library.</span>
    </div>
  <?php endif; ?>
</div>

      </section>

      <section class="wnq-ai-tab-panel <?php echo $active_tab === 'payload' ? 'is-active' : ''; ?>" id="wnq-ai-tab-payload" data-wnq-ai-panel="payload" role="tabpanel" <?php echo $active_tab === 'payload' ? '' : 'hidden'; ?>>
        <div class="wnq-hub-section wnq-ai-card" id="wnq-ai-payload">
  <div class="wnq-hub-section-header">
    <div>
      <h2><span class="wnq-ai-step">2</span>AI Variable Payload Generator</h2>
      <p>Select the templates you want to use, describe the page, and AI will generate the JSON variables for those placeholders.</p>
    </div>
  </div>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="wnq-ai-variable-form">
    <?php wp_nonce_field('wnq_ai_elementor_generate_variables'); ?>
    <input type="hidden" name="action" value="wnq_ai_elementor_generate_variables">

    <div class="wnq-ai-elementor-template-source">
      <strong>Templates to write for</strong>
      <div class="wnq-ai-elementor-section-list">
        <?php foreach ($section_templates as $key => $template): ?>
          <label>
            <input type="checkbox" name="ai_section_template_keys[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, [ElementorSectionLibrary::LOCAL_SERVICE_HERO, ElementorSectionLibrary::CONTENT_IMAGE], true)); ?>>
            <span><?php echo esc_html($template['label'] ?? $key); ?></span>
            <?php if (!empty($template['description'])): ?>
              <small><?php echo esc_html($template['description']); ?></small>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="wnq-ai-template-meta">
      <label><strong>Business Name</strong><input type="text" name="ai_business_name" placeholder="Golden Web Marketing"></label>
      <label><strong>Service</strong><input type="text" name="ai_service" placeholder="Website Design"></label>
      <label><strong>City</strong><input type="text" name="ai_city" placeholder="Orlando"></label>
      <label><strong>State</strong><input type="text" name="ai_state" placeholder="FL"></label>
      <label><strong>Tone</strong><input type="text" name="ai_tone" value="professional, clear, conversion-focused"></label>
      <label><strong>Theme Style</strong><input type="text" name="ai_theme_style" placeholder="dark, premium, gold accent"></label>
    </div>

    <label class="wnq-ai-template-description">
      <strong>Page Goal</strong>
      <input type="text" name="ai_page_goal" placeholder="Generate more calls and strategy call bookings from local service businesses">
    </label>
    <label class="wnq-ai-template-description">
      <strong>Brand Notes</strong>
      <textarea name="ai_brand_notes" rows="5" placeholder="Company details, audience, differentiators, CTA preferences, service notes."></textarea>
    </label>

    <p><button type="submit" class="wnq-btn wnq-btn-primary">Generate JSON Variable Payload</button></p>
  </form>
</div>

      </section>

      <section class="wnq-ai-tab-panel <?php echo $active_tab === 'draft' ? 'is-active' : ''; ?>" id="wnq-ai-tab-draft" data-wnq-ai-panel="draft" role="tabpanel" <?php echo $active_tab === 'draft' ? '' : 'hidden'; ?>>
        <div class="wnq-hub-section wnq-ai-card wnq-ai-card-wide" id="wnq-draft-builder">
  <div class="wnq-hub-section-header">
    <div>
      <h2><span class="wnq-ai-step">3</span>Generate Editable Elementor Draft</h2>
      <p>Choose one or more reusable Elementor sections or paste a custom Elementor JSON export, then provide a JSON payload of variables. The page is always created as a draft.</p>
    </div>
  </div>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="wnq-ai-elementor-form">
    <?php wp_nonce_field('wnq_ai_elementor_generate'); ?>
    <input type="hidden" name="action" value="wnq_ai_elementor_generate">

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

    <div class="wnq-ai-elementor-template-source">
      <strong>Section Templates</strong>
      <div class="wnq-ai-elementor-section-list">
        <?php foreach ($section_templates as $key => $template): ?>
          <label>
            <input type="checkbox" name="section_template_keys[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, [ElementorSectionLibrary::LOCAL_SERVICE_HERO, ElementorSectionLibrary::CONTENT_IMAGE], true)); ?>>
            <span><?php echo esc_html($template['label'] ?? $key); ?></span>
            <?php if (!empty($template['description'])): ?>
              <small><?php echo esc_html($template['description']); ?></small>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
      <label class="wnq-ai-elementor-custom-toggle">
        <input type="checkbox" name="use_custom_template" value="1">
        Use custom pasted/uploaded Elementor JSON instead
      </label>
      <p class="description">Select multiple built-in sections to generate a full draft page in that order. Use custom only when you want to paste a complete Elementor export.</p>
    </div>

    <div class="wnq-ai-elementor-grid">
      <div class="wnq-ai-elementor-field">
        <label for="wnq_elementor_template"><strong>Custom Elementor JSON Template</strong></label>
        <p class="description">Only needed when Section Template is set to Custom. If a file is uploaded, it overrides the pasted text.</p>
        <input type="file" name="elementor_template_file" accept=".json,application/json">
        <textarea id="wnq_elementor_template" name="elementor_template" rows="18" spellcheck="false" placeholder='{"content":[...],"page_settings":{"hide_title":"yes"}}'></textarea>
      </div>

      <div class="wnq-ai-elementor-field">
        <label for="wnq_variables_json"><strong>JSON Variable Payload</strong></label>
        <p class="description">Use the variables from the selected sections. Public remote image URLs are imported by the client agent. ChatGPT image URLs are usually private, so use the image upload fields below for those.</p>
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

    <div class="wnq-ai-elementor-image-uploads">
      <h3>Optional Image Uploads</h3>
      <p class="description">Use these for ChatGPT/private image links. For the fastest pages, upload compressed WebP images when possible, keep files under 5 MB, and include matching ALT variables in the JSON payload.</p>
      <div class="wnq-ai-elementor-image-grid">
        <?php foreach (self::imageUploadFields() as $field => $label): ?>
          <label>
            <strong><?php echo esc_html($label); ?></strong>
            <code>{{<?php echo esc_html($field); ?>}}</code>
            <input type="file" name="<?php echo esc_attr('image_upload_' . $field); ?>" accept="image/jpeg,image/png,image/gif,image/webp">
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="wnq-ai-elementor-image-uploads">
      <h3>Custom Image Upload Mappings</h3>
      <p class="description">For your uploaded templates, enter the placeholder name such as <code>gallery_image_1_url</code>, then upload the image. This supports any custom <code>{{*_image_url}}</code> placeholder.</p>
      <div class="wnq-ai-custom-image-grid">
        <?php for ($i = 1; $i <= 6; $i++): ?>
          <label>
            <strong>Custom image <?php echo (int)$i; ?></strong>
            <input type="text" name="<?php echo esc_attr('custom_image_field_' . $i); ?>" placeholder="example_image_url">
            <input type="file" name="<?php echo esc_attr('custom_image_upload_' . $i); ?>" accept="image/jpeg,image/png,image/gif,image/webp">
          </label>
        <?php endfor; ?>
      </div>
    </div>

    <p>
      <button type="submit" class="wnq-btn wnq-btn-primary">Generate Draft Page</button>
    </p>
  </form>
</div>

        <details class="wnq-ai-help-details">
          <summary><strong>Examples and copy helpers</strong></summary>
          <p class="description">These reusable sections can be stacked into one draft. Paste the example variables, change the copy/URLs, select a client site, and generate a draft.</p>
          <div class="wnq-ai-elementor-grid">
            <details>
              <summary><strong>Built-in Hero Elementor JSON</strong></summary>
              <pre><?php echo esc_html(wp_json_encode(self::exampleTemplate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
            </details>
            <details>
              <summary><strong>Example JSON Variable Payload</strong></summary>
              <pre><?php echo esc_html(wp_json_encode(self::exampleVariables(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
            </details>
          </div>
        </details>
      </section>
    </div>
  </div>
</div>

<style>
  .wnq-ai-builder-page {
    --ai-bg: #070909;
    --ai-panel: #101314;
    --ai-panel-2: #15191b;
    --ai-line: rgba(217, 190, 66, 0.18);
    --ai-line-strong: rgba(217, 190, 66, 0.42);
    --ai-gold: #d9be42;
    --ai-gold-2: #f1d572;
    --ai-text: #f6f2df;
    --ai-muted: #a6acb3;
    --ai-muted-2: #7d858e;
    --ai-input: #0b0e10;
    background:
      radial-gradient(circle at 18% 0%, rgba(217, 190, 66, 0.13), transparent 30%),
      linear-gradient(135deg, #070909 0%, #111517 52%, #080909 100%);
    border: 1px solid rgba(217, 190, 66, 0.16);
    border-radius: 22px;
    box-shadow: 0 24px 70px rgba(0, 0, 0, 0.24);
    color: var(--ai-text);
    margin: 18px 0 30px;
    overflow: hidden;
    padding: 22px;
  }
  .wnq-ai-builder-page * {
    box-sizing: border-box;
  }
  .wnq-ai-builder-page .description,
  .wnq-ai-builder-page .wnq-hub-section-header p {
    color: var(--ai-muted);
  }
  .wnq-ai-builder-hero {
    align-items: stretch;
    border: 1px solid var(--ai-line);
    border-radius: 20px;
    display: grid;
    gap: 20px;
    grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.75fr);
    margin-bottom: 20px;
    padding: 24px;
    background:
      linear-gradient(135deg, rgba(217, 190, 66, 0.12), rgba(217, 190, 66, 0.02)),
      rgba(255, 255, 255, 0.02);
  }
  .wnq-ai-eyebrow {
    color: var(--ai-gold-2);
    display: inline-flex;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.1em;
    margin-bottom: 8px;
    text-transform: uppercase;
  }
  .wnq-ai-builder-hero h2 {
    color: var(--ai-text);
    font-size: clamp(28px, 4vw, 44px);
    line-height: 1.03;
    margin: 0;
    max-width: 820px;
  }
  .wnq-ai-builder-hero p {
    color: #d9dde2;
    font-size: 15px;
    margin: 12px 0 0;
    max-width: 720px;
  }
  .wnq-ai-quick-links {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 20px;
  }
  .wnq-ai-quick-links a {
    border: 1px solid var(--ai-line);
    border-radius: 999px;
    color: var(--ai-text);
    padding: 8px 12px;
    text-decoration: none;
  }
  .wnq-ai-quick-links a:hover,
  .wnq-ai-quick-links a:focus {
    border-color: var(--ai-gold);
    color: var(--ai-gold-2);
  }
  .wnq-ai-builder-stats {
    align-content: stretch;
    display: grid;
    gap: 12px;
  }
  .wnq-ai-builder-stats div {
    background: rgba(0, 0, 0, 0.22);
    border: 1px solid var(--ai-line);
    border-radius: 16px;
    padding: 16px;
  }
  .wnq-ai-builder-stats strong {
    color: var(--ai-gold-2);
    display: block;
    font-size: 28px;
    line-height: 1;
  }
  .wnq-ai-builder-stats span {
    color: var(--ai-muted);
    display: block;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.08em;
    margin-top: 7px;
    text-transform: uppercase;
  }
  .wnq-ai-workflow-grid {
    display: grid;
    gap: 18px;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  }
  .wnq-ai-card {
    background:
      linear-gradient(180deg, rgba(255, 255, 255, 0.035), transparent),
      var(--ai-panel);
    border: 1px solid var(--ai-line);
    border-radius: 18px;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
    margin: 0;
    padding: 18px;
  }
  .wnq-ai-card-wide {
    grid-column: 1 / -1;
  }
  .wnq-ai-examples {
    margin-top: 18px;
  }
  .wnq-ai-builder-page .wnq-hub-section-header {
    border-bottom: 1px solid rgba(217, 190, 66, 0.12);
    margin-bottom: 16px;
    padding-bottom: 14px;
  }
  .wnq-ai-builder-page .wnq-hub-section-header h2,
  .wnq-ai-builder-page > .wnq-ai-card h2 {
    align-items: center;
    color: var(--ai-text);
    display: flex;
    gap: 10px;
    margin: 0;
  }
  .wnq-ai-step {
    align-items: center;
    border: 1px solid var(--ai-gold);
    border-radius: 999px;
    color: var(--ai-gold-2);
    display: inline-flex;
    flex: 0 0 auto;
    font-size: 14px;
    height: 34px;
    justify-content: center;
    width: 34px;
  }
  .wnq-ai-builder-page label strong,
  .wnq-ai-elementor-template-source > strong,
  .wnq-ai-elementor-image-uploads h3 {
    color: var(--ai-text);
  }
  .wnq-ai-elementor-grid,
  .wnq-ai-template-meta,
  .wnq-ai-elementor-options,
  .wnq-ai-elementor-image-grid,
  .wnq-ai-custom-image-grid {
    display: grid;
    gap: 14px;
  }
  .wnq-ai-elementor-grid {
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  }
  .wnq-ai-template-meta,
  .wnq-ai-elementor-options {
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    margin-bottom: 14px;
  }
  .wnq-ai-elementor-image-grid,
  .wnq-ai-custom-image-grid {
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    margin-top: 12px;
  }
  .wnq-ai-builder-page input[type="text"],
  .wnq-ai-builder-page input[type="number"],
  .wnq-ai-builder-page select,
  .wnq-ai-builder-page textarea {
    background: var(--ai-input);
    border: 1px solid rgba(255, 255, 255, 0.11);
    border-radius: 10px;
    box-shadow: none;
    color: var(--ai-text);
    margin-top: 8px;
    min-height: 42px;
    width: 100%;
  }
  .wnq-ai-builder-page input[type="text"]:focus,
  .wnq-ai-builder-page input[type="number"]:focus,
  .wnq-ai-builder-page select:focus,
  .wnq-ai-builder-page textarea:focus {
    border-color: var(--ai-gold);
    box-shadow: 0 0 0 2px rgba(217, 190, 66, 0.18);
    outline: none;
  }
  .wnq-ai-builder-page textarea,
  .wnq-ai-generated-payload {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 13px;
    line-height: 1.5;
  }
  .wnq-ai-builder-page input[type="file"] {
    background: rgba(255, 255, 255, 0.03);
    border: 1px dashed var(--ai-line-strong);
    border-radius: 12px;
    color: var(--ai-muted);
    display: block;
    margin-top: 8px;
    padding: 12px;
    width: 100%;
  }
  .wnq-ai-builder-page .wnq-btn,
  .wnq-ai-builder-page .button {
    border-radius: 10px;
    font-weight: 800;
  }
  .wnq-ai-builder-page .wnq-btn-primary {
    background: linear-gradient(135deg, var(--ai-gold-2), #b78924);
    border: 0;
    box-shadow: 0 10px 25px rgba(217, 190, 66, 0.2);
    color: #161100;
    padding: 11px 18px;
  }
  .wnq-ai-builder-page .wnq-btn-primary:hover,
  .wnq-ai-builder-page .wnq-btn-primary:focus {
    filter: brightness(1.06);
    transform: translateY(-1px);
  }
  .wnq-ai-template-description {
    display: block;
    margin: 14px 0;
  }
  .wnq-ai-template-library-list,
  .wnq-ai-elementor-section-list {
    display: grid;
    gap: 10px;
    margin-top: 12px;
  }
  .wnq-ai-template-card,
  .wnq-ai-elementor-section-list label,
  .wnq-ai-elementor-custom-toggle,
  .wnq-ai-elementor-image-grid label,
  .wnq-ai-custom-image-grid label,
  .wnq-ai-empty-state {
    background: var(--ai-panel-2);
    border: 1px solid rgba(255, 255, 255, 0.09);
    border-radius: 14px;
    padding: 13px;
  }
  .wnq-ai-template-card {
    align-items: flex-start;
    display: flex;
    gap: 16px;
    justify-content: space-between;
  }
  .wnq-ai-template-card:hover,
  .wnq-ai-elementor-section-list label:hover,
  .wnq-ai-elementor-custom-toggle:hover,
  .wnq-ai-elementor-image-grid label:hover,
  .wnq-ai-custom-image-grid label:hover {
    border-color: var(--ai-line-strong);
  }
  .wnq-ai-template-card span,
  .wnq-ai-template-card p,
  .wnq-ai-elementor-section-list small,
  .wnq-ai-empty-state span {
    color: var(--ai-muted);
    display: block;
    font-size: 13px;
    margin: 4px 0 0;
  }
  .wnq-ai-empty-state strong {
    color: var(--ai-text);
    display: block;
  }
  .wnq-ai-variable-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
  }
  .wnq-ai-variable-chips code,
  .wnq-ai-elementor-image-grid code,
  .wnq-ai-builder-page code {
    background: rgba(217, 190, 66, 0.12);
    border: 1px solid rgba(217, 190, 66, 0.18);
    border-radius: 999px;
    color: var(--ai-gold-2);
    padding: 4px 8px;
  }
  .wnq-ai-elementor-section-list label,
  .wnq-ai-elementor-custom-toggle {
    align-items: flex-start;
    display: grid;
    gap: 4px 10px;
    grid-template-columns: auto 1fr;
  }
  .wnq-ai-elementor-section-list small {
    grid-column: 2;
  }
  .wnq-ai-elementor-section-list input[type="checkbox"],
  .wnq-ai-elementor-custom-toggle input[type="checkbox"] {
    accent-color: var(--ai-gold);
    margin-top: 3px;
  }
  .wnq-ai-elementor-target,
  .wnq-ai-elementor-template-source,
  .wnq-ai-elementor-image-uploads {
    margin-bottom: 20px;
  }
  .wnq-ai-elementor-field textarea {
    margin-top: 10px;
  }
  .wnq-ai-builder-page details {
    background: var(--ai-panel-2);
    border: 1px solid rgba(255, 255, 255, 0.09);
    border-radius: 14px;
    overflow: hidden;
  }
  .wnq-ai-builder-page summary {
    color: var(--ai-text);
    cursor: pointer;
    padding: 13px 14px;
  }
  .wnq-ai-elementor-grid pre {
    background: #080a0b;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    color: #dfe6e9;
    margin: 0;
    max-height: 420px;
    overflow: auto;
    padding: 16px;
    white-space: pre-wrap;
  }
  .wnq-ai-generated-payload {
    background: #080a0b;
    border: 1px solid rgba(217, 190, 66, 0.24);
    color: var(--ai-text);
    margin-top: 10px;
    width: 100%;
  }
  @media (max-width: 1180px) {
    .wnq-ai-builder-hero,
    .wnq-ai-workflow-grid {
      grid-template-columns: 1fr;
    }
  }
  @media (max-width: 782px) {
    .wnq-ai-builder-page {
      border-radius: 14px;
      padding: 14px;
    }
    .wnq-ai-builder-hero,
    .wnq-ai-card {
      padding: 16px;
    }
    .wnq-ai-template-card {
      display: grid;
    }
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
        $use_custom_template = !empty($_POST['use_custom_template']);
        $section_template_keys = isset($_POST['section_template_keys']) && is_array($_POST['section_template_keys'])
            ? array_map('sanitize_key', wp_unslash($_POST['section_template_keys']))
            : [ElementorSectionLibrary::LOCAL_SERVICE_HERO];
        $section_template_keys = array_values(array_filter($section_template_keys));
        $template_json = '';
        $variables_json = self::readTextInputOrFile('variables_json', 'variables_json_file');
        $variables = json_decode(trim($variables_json), true);

        if (!is_array($variables)) {
            self::redirectWithError('Invalid variable JSON: ' . json_last_error_msg());
        }

        if ($use_custom_template) {
            $template_json = self::readTextInputOrFile('elementor_template', 'elementor_template_file');
        } else {
            if (!$section_template_keys) {
                self::redirectWithError('Select at least one Elementor section template.');
            }

            $template = ElementorSectionLibrary::compose($section_template_keys);
            if (!$template) {
                self::redirectWithError('Unknown Elementor section template selected.');
            }

            $template_json = (string)wp_json_encode($template, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $variables = array_merge(ElementorSectionLibrary::defaultsFor($section_template_keys), $variables);
        }

        $uploaded_images = self::uploadedImageVariables($variables);
        if (is_wp_error($uploaded_images)) {
            self::redirectWithError($uploaded_images->get_error_message());
        }
        if ($uploaded_images) {
            $variables = self::mergeVariables($variables, $uploaded_images);
        }

        $custom_uploaded_images = self::customUploadedImageVariables($variables);
        if (is_wp_error($custom_uploaded_images)) {
            self::redirectWithError($custom_uploaded_images->get_error_message());
        }
        if ($custom_uploaded_images) {
            $variables = self::mergeVariables($variables, $custom_uploaded_images);
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

    public static function handleSaveTemplate(): void
    {
        self::checkCap();
        check_admin_referer('wnq_ai_elementor_save_template');

        $json = self::readTextInputOrFile('library_template_json', 'library_template_file');
        $result = ElementorTemplateLibrary::save(
            sanitize_text_field(wp_unslash($_POST['template_name'] ?? '')),
            sanitize_text_field(wp_unslash($_POST['template_category'] ?? 'Custom')),
            sanitize_key(wp_unslash($_POST['template_theme'] ?? 'any')),
            sanitize_textarea_field(wp_unslash($_POST['template_description'] ?? '')),
            $json
        );

        if (empty($result['success'])) {
            self::redirectWithError((string)($result['message'] ?? 'Template could not be saved.'));
        }

        wp_safe_redirect(add_query_arg([
            'page'   => 'wnq-seo-hub-ai-elementor',
            'notice' => 'template_saved',
        ], admin_url('admin.php')));
        exit;
    }

    public static function handleDeleteTemplate(): void
    {
        self::checkCap();
        $key = sanitize_key(wp_unslash($_POST['template_key'] ?? ''));
        check_admin_referer('wnq_ai_elementor_delete_template_' . $key);

        ElementorTemplateLibrary::delete($key);

        wp_safe_redirect(add_query_arg([
            'page'   => 'wnq-seo-hub-ai-elementor',
            'notice' => 'template_deleted',
        ], admin_url('admin.php')));
        exit;
    }

    public static function handleGenerateVariables(): void
    {
        self::checkCap();
        check_admin_referer('wnq_ai_elementor_generate_variables');

        $keys = isset($_POST['ai_section_template_keys']) && is_array($_POST['ai_section_template_keys'])
            ? array_map('sanitize_key', wp_unslash($_POST['ai_section_template_keys']))
            : [ElementorSectionLibrary::LOCAL_SERVICE_HERO, ElementorSectionLibrary::CONTENT_IMAGE];
        $keys = array_values(array_filter($keys));

        $template = ElementorSectionLibrary::compose($keys);
        if (!$template) {
            self::redirectWithError('Select at least one template for the AI variable payload.');
        }

        $variables = ElementorTemplateLibrary::scanVariables($template);
        if (!$variables) {
            self::redirectWithError('The selected template does not contain any {{variables}} for AI to fill.');
        }

        $image_variables = ElementorTemplateLibrary::imageFieldsFromVariables($variables);
        $result = AIEngine::generate('elementor_variable_payload', [
            'business_name'   => sanitize_text_field(wp_unslash($_POST['ai_business_name'] ?? '')),
            'brand_notes'     => sanitize_textarea_field(wp_unslash($_POST['ai_brand_notes'] ?? '')),
            'service'         => sanitize_text_field(wp_unslash($_POST['ai_service'] ?? '')),
            'city'            => sanitize_text_field(wp_unslash($_POST['ai_city'] ?? '')),
            'state'           => sanitize_text_field(wp_unslash($_POST['ai_state'] ?? '')),
            'audience'        => 'local service business customers',
            'page_goal'       => sanitize_text_field(wp_unslash($_POST['ai_page_goal'] ?? '')),
            'tone'            => sanitize_text_field(wp_unslash($_POST['ai_tone'] ?? 'professional, clear, conversion-focused')),
            'theme_style'     => sanitize_text_field(wp_unslash($_POST['ai_theme_style'] ?? '')),
            'variables'       => implode("\n", array_map(static fn($key) => '- ' . $key, $variables)),
            'image_variables' => $image_variables ? implode("\n", array_map(static fn($key) => '- ' . $key, $image_variables)) : 'None',
        ], '', [
            'max_tokens'  => 3000,
            'temperature' => 0.45,
            'no_cache'    => true,
        ]);

        if (empty($result['success'])) {
            self::redirectWithError('AI variable generation failed: ' . (string)($result['error'] ?? 'unknown error'));
        }

        $json = self::extractJsonObject((string)($result['content'] ?? ''));
        if ($json === '') {
            self::redirectWithError('AI did not return a valid JSON object. Try again or check AI settings.');
        }

        set_transient(self::aiPayloadTransientKey(), ['json' => $json], 10 * MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg([
            'page'   => 'wnq-seo-hub-ai-elementor',
            'notice' => 'ai_payload',
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

    private static function mergeVariables(array $base, array $incoming): array
    {
        $uploaded = array_merge(
            (array)($base['uploaded_image_fields'] ?? []),
            (array)($incoming['uploaded_image_fields'] ?? [])
        );

        $merged = array_merge($base, $incoming);
        if ($uploaded) {
            $merged['uploaded_image_fields'] = array_values(array_unique(array_map([self::class, 'cleanPlaceholderKey'], $uploaded)));
        }

        return $merged;
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
            'wnq-seo-hub-images'       => 'Image Optimizer',
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

    private static function aiPayloadTransientKey(): string
    {
        return 'wnq_ai_elementor_payload_' . get_current_user_id();
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

    private static function imageUploadFields(): array
    {
        return [
            'hero_background_image_url' => 'Hero background image',
            'hero_slide_1_url'          => 'Hero slide 1',
            'hero_slide_2_url'          => 'Hero slide 2',
            'hero_slide_3_url'          => 'Hero slide 3',
            'content_image_url'         => 'Content section image',
        ];
    }

    private static function uploadedImageVariables(array $existing_variables = [])
    {
        $variables = [];
        $max_bytes = 5 * 1024 * 1024;

        foreach (self::imageUploadFields() as $field => $label) {
            $input = 'image_upload_' . $field;
            if (empty($_FILES[$input]) || !is_array($_FILES[$input])) {
                continue;
            }

            $file = $_FILES[$input];
            $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                return new \WP_Error('wnq_image_upload_failed', $label . ' upload failed.');
            }

            $tmp_name = (string)($file['tmp_name'] ?? '');
            $name = sanitize_file_name((string)($file['name'] ?? $field));
            $size = (int)($file['size'] ?? 0);
            if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
                return new \WP_Error('wnq_image_upload_invalid', $label . ' was not a valid uploaded file.');
            }
            if ($size <= 0 || $size > $max_bytes) {
                return new \WP_Error('wnq_image_upload_size', $label . ' must be 5 MB or smaller.');
            }

            $mime = self::detectUploadedImageMime($tmp_name, $name);
            if ($mime === '') {
                return new \WP_Error('wnq_image_upload_type', $label . ' must be a JPG, PNG, GIF, or WebP image.');
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $attachment_id = media_handle_upload($input, 0, [], ['test_form' => false]);
            if (is_wp_error($attachment_id)) {
                return new \WP_Error('wnq_image_upload_media', $label . ' could not be added to the hub Media Library: ' . $attachment_id->get_error_message());
            }

            $url = wp_get_attachment_url((int)$attachment_id);
            if (!$url) {
                return new \WP_Error('wnq_image_upload_url', $label . ' was uploaded, but WordPress did not return a media URL.');
            }

            $variables[$field] = esc_url_raw((string)$url);
            $variables['uploaded_image_fields'][] = $field;

            $alt_field = str_replace('_url', '_alt', $field);
            if (empty($existing_variables[$alt_field])) {
                $alt = preg_replace('/\.[a-z0-9]+$/i', '', str_replace(['-', '_'], ' ', $name));
                $variables[$alt_field] = $alt;
                update_post_meta((int)$attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string)$alt));
            }
        }

        return $variables;
    }

    private static function customUploadedImageVariables(array $existing_variables = [])
    {
        $variables = [];
        $max_bytes = 5 * 1024 * 1024;

        for ($i = 1; $i <= 6; $i++) {
            $field = self::cleanPlaceholderKey((string)wp_unslash($_POST['custom_image_field_' . $i] ?? ''));
            $input = 'custom_image_upload_' . $i;

            if ($field === '') {
                continue;
            }
            if (empty($_FILES[$input]) || !is_array($_FILES[$input])) {
                continue;
            }

            $file = $_FILES[$input];
            $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                return new \WP_Error('wnq_custom_image_upload_failed', 'Custom image ' . $i . ' upload failed.');
            }

            $tmp_name = (string)($file['tmp_name'] ?? '');
            $name = sanitize_file_name((string)($file['name'] ?? $field));
            $size = (int)($file['size'] ?? 0);
            if ($tmp_name === '' || !is_uploaded_file($tmp_name)) {
                return new \WP_Error('wnq_custom_image_upload_invalid', 'Custom image ' . $i . ' was not a valid uploaded file.');
            }
            if ($size <= 0 || $size > $max_bytes) {
                return new \WP_Error('wnq_custom_image_upload_size', 'Custom image ' . $i . ' must be 5 MB or smaller.');
            }

            $mime = self::detectUploadedImageMime($tmp_name, $name);
            if ($mime === '') {
                return new \WP_Error('wnq_custom_image_upload_type', 'Custom image ' . $i . ' must be a JPG, PNG, GIF, or WebP image.');
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';

            $attachment_id = media_handle_upload($input, 0, [], ['test_form' => false]);
            if (is_wp_error($attachment_id)) {
                return new \WP_Error('wnq_custom_image_upload_media', 'Custom image ' . $i . ' could not be added to the hub Media Library: ' . $attachment_id->get_error_message());
            }

            $url = wp_get_attachment_url((int)$attachment_id);
            if (!$url) {
                return new \WP_Error('wnq_custom_image_upload_url', 'Custom image ' . $i . ' was uploaded, but WordPress did not return a media URL.');
            }

            $variables[$field] = esc_url_raw((string)$url);
            $variables['uploaded_image_fields'][] = $field;

            $alt_field = preg_replace('/_url$/', '_alt', $field);
            if (is_string($alt_field) && $alt_field !== $field && empty($existing_variables[$alt_field])) {
                $alt = preg_replace('/\.[a-z0-9]+$/i', '', str_replace(['-', '_'], ' ', $name));
                $variables[$alt_field] = $alt;
                update_post_meta((int)$attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string)$alt));
            }
        }

        return $variables;
    }

    private static function detectUploadedImageMime(string $tmp_name, string $name): string
    {
        $mime = '';

        if (!function_exists('wp_check_filetype_and_ext')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (function_exists('wp_check_filetype_and_ext')) {
            $checked = wp_check_filetype_and_ext($tmp_name, $name);
            if (is_array($checked) && !empty($checked['type'])) {
                $mime = (string)$checked['type'];
            }
        }

        if ($mime === '' && function_exists('getimagesize')) {
            $size = @getimagesize($tmp_name);
            if (is_array($size) && !empty($size['mime'])) {
                $mime = (string)$size['mime'];
            }
        }

        $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        return in_array($mime, $allowed, true) ? $mime : '';
    }

    private static function cleanPlaceholderKey(string $key): string
    {
        $key = strtolower(trim($key));
        $key = preg_replace('/[^a-z0-9_]/', '_', $key);
        $key = preg_replace('/_+/', '_', (string)$key);

        return trim((string)$key, '_');
    }

    private static function extractJsonObject(string $raw): string
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $raw = preg_replace('/\s*```$/', '', (string)$raw);
        $raw = trim((string)$raw);

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return (string)wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return '';
        }

        $candidate = substr($raw, $start, $end - $start + 1);
        $decoded = json_decode($candidate, true);
        if (!is_array($decoded)) {
            return '';
        }

        return (string)wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function exampleVariables(): array
    {
        return ElementorSectionLibrary::exampleVariables(ElementorSectionLibrary::LOCAL_SERVICE_HERO);
    }

    private static function exampleTemplate(): array
    {
        return ElementorSectionLibrary::compose([
            ElementorSectionLibrary::LOCAL_SERVICE_HERO,
            ElementorSectionLibrary::CONTENT_IMAGE,
        ]) ?: [];
    }
}

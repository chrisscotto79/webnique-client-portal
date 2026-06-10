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
            echo '<div class="wnq-ai-success-state">';
            echo '<span class="wnq-ai-success-icon" aria-hidden="true">&#10003;</span>';
            echo '<div><span class="wnq-ai-eyebrow">Elementor draft ready</span><h2>Draft created successfully.</h2>';
            echo '<dl class="wnq-ai-success-meta">';
            echo '<div><dt>Page title</dt><dd>' . esc_html($result['post_title'] ?? 'Elementor Draft') . '</dd></div>';
            echo '<div><dt>Client site</dt><dd>' . esc_html($result['site_url'] ?? 'Client site') . '</dd></div>';
            echo '<div><dt>Status</dt><dd>WordPress Draft</dd></div>';
            echo '</dl>';
            if (isset($result['images_imported'])) {
                echo '<p class="description">Images imported to client media: ' . esc_html((string)absint($result['images_imported'])) . '</p>';
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
            if (!empty($result['featured_image_error'])) {
                echo '<p><strong>Featured image warning:</strong> ' . esc_html((string)$result['featured_image_error']) . '</p>';
            }
            echo '<p class="wnq-ai-success-actions">';
            if (!empty($result['elementor_url'])) {
                echo '<a class="wnq-btn wnq-btn-primary" target="_blank" rel="noopener" href="' . esc_url($result['elementor_url']) . '">Edit in Elementor</a>';
            }
            if (!empty($result['preview_url'])) {
                echo '<a class="wnq-btn" target="_blank" rel="noopener" href="' . esc_url($result['preview_url']) . '">View Draft</a>';
            }
            echo '<a class="wnq-btn" href="' . esc_url(admin_url('admin.php?page=wnq-seo-hub-ai-elementor')) . '">Create Another Page</a>';
            echo '</p></div></div>';
        }

        if ($error !== '') {
            echo '<div class="wnq-hub-notice error"><p>' . esc_html($error) . '</p></div>';
        }
        if ($notice === 'template_saved') {
            echo '<div class="wnq-hub-notice success"><p>Template saved to the library.</p></div>';
        } elseif ($notice === 'template_saved_no_variables') {
            echo '<div class="wnq-hub-notice warning"><p><strong>Template saved, but no AI placeholders were detected.</strong> Its hardcoded text will remain editable in Elementor, but the AI Payload step cannot personalize it until you add values such as <code>{{business_name}}</code>, <code>{{service}}</code>, or <code>{{city}}</code>.</p></div>';
        } elseif ($notice === 'template_deleted') {
            echo '<div class="wnq-hub-notice success"><p>Template deleted from the library.</p></div>';
        }
        if (is_array($ai_payload)) {
            echo '<div class="wnq-hub-notice success"><p><strong>AI variable payload generated.</strong> Open Advanced Mode, paste this into the JSON payload box, review it, then generate the draft.</p>';
            if (!empty($ai_payload['warnings']) && is_array($ai_payload['warnings'])) {
                echo '<p><strong>Review these remaining content notes:</strong></p><ul>';
                foreach ((array)$ai_payload['warnings'] as $warning) {
                    echo '<li>' . esc_html((string)$warning) . '</li>';
                }
                echo '</ul>';
            } else {
                echo '<p><strong>Quality checks passed:</strong> required variables are present, substantial copy meets minimum lengths, and no repeated content was detected.</p>';
            }
            echo '<textarea class="wnq-ai-generated-payload" rows="18" readonly spellcheck="false">' . esc_textarea((string)($ai_payload['json'] ?? '')) . '</textarea>';
            echo '</div>';
        }

        $saved_templates = ElementorTemplateLibrary::all();
        $agents = self::connectedAgents();
        $section_groups = self::groupSectionTemplates($section_templates);
        $saved_template_groups = self::groupSavedTemplates($saved_templates);
        $category_labels = self::templateCategoryLabels();
        $connected_site_count = count($agents);
        $available_template_count = count($section_templates);
        $builder_stats = get_option('wnq_ai_elementor_builder_stats', []);
        $drafts_this_month = (string)(
            sanitize_text_field((string)($builder_stats['month'] ?? '')) === current_time('Y-m')
                ? absint($builder_stats['month_count'] ?? 0)
                : 0
        );
        $last_generated_page = sanitize_text_field((string)($builder_stats['last_title'] ?? 'None yet'));
        $active_tab = 'draft';
        if (in_array($notice, ['template_saved', 'template_saved_no_variables', 'template_deleted'], true)) {
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
        <strong><?php echo esc_html($drafts_this_month); ?></strong>
        <span>Drafts This Month</span>
      </div>
      <div>
        <strong class="wnq-ai-stat-title"><?php echo esc_html($last_generated_page); ?></strong>
        <span>Last Generated Page</span>
      </div>
    </div>
  </div>

  <div class="wnq-ai-tab-shell">
    <div class="wnq-ai-tabs" role="tablist" aria-label="AI Elementor Builder workflow">
      <button type="button" class="wnq-ai-tab <?php echo $active_tab === 'library' ? 'is-active' : ''; ?>" data-wnq-ai-tab="library" role="tab" aria-selected="<?php echo $active_tab === 'library' ? 'true' : 'false'; ?>" aria-controls="wnq-ai-tab-library"><span>01</span><strong>Template Library</strong><small>Upload and organize sections</small></button>
      <button type="button" class="wnq-ai-tab <?php echo $active_tab === 'payload' ? 'is-active' : ''; ?>" data-wnq-ai-tab="payload" role="tab" aria-selected="<?php echo $active_tab === 'payload' ? 'true' : 'false'; ?>" aria-controls="wnq-ai-tab-payload"><span>02</span><strong>AI Payload</strong><small>Generate variables for sections</small></button>
      <button type="button" class="wnq-ai-tab <?php echo $active_tab === 'draft' ? 'is-active' : ''; ?>" data-wnq-ai-tab="draft" role="tab" aria-selected="<?php echo $active_tab === 'draft' ? 'true' : 'false'; ?>" aria-controls="wnq-ai-tab-draft"><span>03</span><strong>Draft Builder</strong><small>Create the Elementor draft</small></button>
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

  <div class="wnq-ai-template-guidance">
    <strong>Build templates for reuse</strong>
    <span>Keep universal labels as normal text. Replace client-specific copy, links, colors, and images with <code>{{placeholders}}</code> so the AI Payload step can personalize them.</span>
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
        <select name="template_category">
          <?php foreach ($category_labels as $category_key => $category_label): ?>
            <option value="<?php echo esc_attr($category_label); ?>" <?php selected($category_key, 'custom'); ?>><?php echo esc_html($category_label); ?></option>
          <?php endforeach; ?>
        </select>
        <span class="description">The category tells the AI what kind of copy this section needs.</span>
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
      <span class="description">Describe the section's purpose so the AI writes copy that fits it.</span>
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
    <?php self::renderSavedTemplateGroups($saved_template_groups); ?>
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
      <?php self::renderSectionTemplatePicker($section_groups, 'ai_section_template_keys', [ElementorSectionLibrary::LOCAL_SERVICE_HERO, ElementorSectionLibrary::CONTENT_IMAGE]); ?>
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
    <div class="wnq-ai-builder-heading">
    <div>
      <span class="wnq-ai-eyebrow">Simple Mode</span>
      <h2>Create a client page draft</h2>
      <p>Move through the guided steps. The builder converts your choices into the Elementor template payload behind the scenes.</p>
      <p><strong>Requirements:</strong> Every generated page uses Elementor Pro and automatically includes a top banner. Contact pages also include the required iframe contact section.</p>
    </div>
    <button type="button" class="wnq-btn" data-wnq-open-advanced>Advanced Mode</button>
  </div>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="wnq-ai-elementor-form" data-wnq-wizard>
    <?php wp_nonce_field('wnq_ai_elementor_generate'); ?>
    <input type="hidden" name="action" value="wnq_ai_elementor_generate">

    <nav class="wnq-ai-wizard-progress" aria-label="Page builder progress">
      <?php foreach (['Choose Client', 'Page Type', 'Page Details', 'Sections', 'Images', 'Preview & Generate'] as $index => $step_label): ?>
        <button type="button" class="<?php echo $index === 0 ? 'is-active' : ''; ?>" data-wnq-step-nav="<?php echo (int)($index + 1); ?>"><span><?php echo (int)($index + 1); ?></span><?php echo esc_html($step_label); ?></button>
      <?php endforeach; ?>
    </nav>

    <section class="wnq-ai-wizard-step is-active" data-wnq-step="1">
      <div class="wnq-ai-step-heading"><span>Step 1</span><h3>Choose Client</h3><p>Select the connected WordPress site that should receive the draft.</p></div>
      <div class="wnq-ai-client-picker">
        <label for="wnq_agent_key_id"><strong>Connected client site</strong></label>
        <select id="wnq_agent_key_id" name="agent_key_id" required data-wnq-summary-source="client">
          <option value="">Choose a connected client site...</option>
          <?php foreach ($agents as $agent): ?>
            <?php
              $site_label = ($agent['site_name'] ?? '') ?: parse_url($agent['site_url'] ?? '', PHP_URL_HOST) ?: ($agent['site_url'] ?? '');
              $label = trim(($agent['client_label'] ?? '') . ' - ' . $site_label);
            ?>
            <option value="<?php echo (int)$agent['id']; ?>" data-client-name="<?php echo esc_attr((string)($agent['client_label'] ?? '')); ?>" data-client-phone="<?php echo esc_attr((string)($agent['client_phone'] ?? '')); ?>" data-client-website="<?php echo esc_attr((string)($agent['client_website'] ?? $agent['site_url'] ?? '')); ?>" data-client-services="<?php echo esc_attr((string)($agent['client_services'] ?? '')); ?>" data-site-url="<?php echo esc_attr((string)($agent['site_url'] ?? '')); ?>"><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (empty($agents)): ?><p class="description">No active sites found. Connect one under <a href="<?php echo esc_url(admin_url('admin.php?page=wnq-seo-hub-api')); ?>">API Management</a>.</p><?php endif; ?>
      </div>
      <div class="wnq-ai-autofill-note"><strong>Client profile ready</strong><span>Business details remain editable. Connected brand profiles can auto-fill these fields as that data becomes available.</span></div>
    </section>

    <section class="wnq-ai-wizard-step" data-wnq-step="2" hidden>
      <div class="wnq-ai-step-heading"><span>Step 2</span><h3>Choose Page Type</h3><p>Start with the page goal. The builder will tailor the recommended fields, sections, and image slots.</p></div>
      <div class="wnq-ai-page-type-grid">
        <?php foreach (self::pageTypes() as $key => $page_type): ?>
          <label><input type="radio" name="page_type" value="<?php echo esc_attr($key); ?>" <?php checked($key, 'home'); ?>><span><strong><?php echo esc_html($page_type['label']); ?></strong><small><?php echo esc_html($page_type['description']); ?></small></span></label>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="wnq-ai-wizard-step" data-wnq-step="3" hidden>
      <div class="wnq-ai-step-heading"><span>Step 3</span><h3>Add Page Details</h3><p>Use normal page fields. These values become the JSON variable payload automatically.</p></div>
      <div class="wnq-ai-simple-fields">
        <?php self::renderSimpleFields(); ?>
      </div>
    </section>

    <section class="wnq-ai-wizard-step" data-wnq-step="4" hidden>
      <div class="wnq-ai-step-heading"><span>Step 4</span><h3>Choose & Order Sections</h3><p>Select reusable Elementor sections, then use the arrows to set their page order. The banner always stays first.</p></div>
      <?php self::renderSimpleSectionCards($section_templates); ?>
    </section>

    <section class="wnq-ai-wizard-step" data-wnq-step="5" hidden>
      <div class="wnq-ai-step-heading"><span>Step 5</span><h3>Upload Images</h3><p>Only relevant image slots are shown. Images are imported to the client site's media library during generation.</p></div>
      <div class="wnq-ai-elementor-image-grid wnq-ai-simple-image-grid">
        <?php foreach (self::imageUploadFields() as $field => $label): ?>
          <label class="wnq-ai-upload-box" data-image-slot="<?php echo esc_attr($field); ?>">
            <span class="wnq-ai-upload-preview" aria-hidden="true">+</span>
            <strong><?php echo esc_html($label); ?></strong>
            <small>Drop or choose JPG, PNG, GIF, or WebP</small>
            <input type="file" name="<?php echo esc_attr('image_upload_' . $field); ?>" accept="image/jpeg,image/png,image/gif,image/webp" data-wnq-image-input>
          </label>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="wnq-ai-wizard-step" data-wnq-step="6" hidden>
      <div class="wnq-ai-step-heading"><span>Step 6</span><h3>Preview & Generate</h3><p>Confirm the plan before creating the editable WordPress draft.</p></div>
      <div class="wnq-ai-summary-card" data-wnq-summary></div>
      <div class="wnq-ai-confirmation-actions">
        <button type="button" class="wnq-btn" data-wnq-go-step="3">Back to Edit</button>
        <button type="submit" class="wnq-btn wnq-btn-primary">Create Elementor Draft</button>
      </div>
    </section>

    <div class="wnq-ai-wizard-actions">
      <button type="button" class="wnq-btn" data-wnq-prev hidden>Back</button>
      <button type="button" class="wnq-btn wnq-btn-primary" data-wnq-next>Continue</button>
    </div>

    <details class="wnq-ai-advanced-mode" data-wnq-advanced>
      <summary><span><strong>Advanced Mode</strong><small>Custom Elementor JSON, raw variables, image mappings, and developer helpers</small></span></summary>
      <div class="wnq-ai-advanced-body">
        <div class="wnq-ai-elementor-template-source">
          <strong>Complete Elementor Template Catalog</strong>
          <p class="description">Choose any built-in or saved template. These selections are combined with the friendly section choices above.</p>
          <?php self::renderSectionTemplatePicker($section_groups, 'section_template_keys', []); ?>
        </div>
        <label class="wnq-ai-elementor-custom-toggle"><input type="checkbox" name="use_custom_template" value="1"> Use custom pasted/uploaded Elementor JSON instead of selected sections</label>
        <div class="wnq-ai-elementor-grid">
          <div class="wnq-ai-elementor-field">
            <label for="wnq_elementor_template"><strong>Custom Elementor JSON Template</strong></label>
            <input type="file" name="elementor_template_file" accept=".json,application/json">
            <textarea id="wnq_elementor_template" name="elementor_template" rows="14" spellcheck="false" placeholder='{"content":[...],"page_settings":{"hide_title":"yes"}}'></textarea>
          </div>
          <div class="wnq-ai-elementor-field">
            <label for="wnq_variables_json"><strong>View / Edit Advanced JSON Payload</strong></label>
            <input type="file" name="variables_json_file" accept=".json,application/json">
            <textarea id="wnq_variables_json" name="variables_json" rows="14" spellcheck="false" placeholder='{"service":"Land Clearing","city":"Lakeland"}'></textarea>
          </div>
        </div>
        <div class="wnq-ai-elementor-options">
          <label><strong>Featured Image ID</strong><input type="number" min="1" step="1" name="featured_image_id" placeholder="Client attachment ID"></label>
        </div>
        <h3>Custom Image Placeholder Mappings</h3>
        <div class="wnq-ai-custom-image-grid">
          <?php for ($i = 1; $i <= 6; $i++): ?>
            <label><strong>Custom image <?php echo (int)$i; ?></strong><input type="text" name="<?php echo esc_attr('custom_image_field_' . $i); ?>" placeholder="example_image_url"><input type="file" name="<?php echo esc_attr('custom_image_upload_' . $i); ?>" accept="image/jpeg,image/png,image/gif,image/webp"></label>
          <?php endfor; ?>
        </div>
        <details class="wnq-ai-help-details"><summary><strong>Built-in JSON examples and debug output</strong></summary><div class="wnq-ai-elementor-grid"><pre><?php echo esc_html(wp_json_encode(self::exampleTemplate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre><pre><?php echo esc_html(wp_json_encode(self::exampleVariables(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre></div></details>
      </div>
    </details>
  </form>
</div>
      </section>
    </div>
  </div>
</div>

<script>
(function() {
  function setTab(root, tabName) {
    root.querySelectorAll('[data-wnq-ai-tab]').forEach(function(button) {
      var active = button.getAttribute('data-wnq-ai-tab') === tabName;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-selected', active ? 'true' : 'false');
    });

    root.querySelectorAll('[data-wnq-ai-panel]').forEach(function(panel) {
      var active = panel.getAttribute('data-wnq-ai-panel') === tabName;
      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
    });

    root.setAttribute('data-active-tab', tabName);
  }

  document.querySelectorAll('.wnq-ai-builder-page').forEach(function(root) {
    var initial = root.getAttribute('data-active-tab') || 'draft';
    setTab(root, initial);

    root.querySelectorAll('[data-wnq-ai-tab]').forEach(function(button) {
      button.addEventListener('click', function() {
        setTab(root, button.getAttribute('data-wnq-ai-tab') || 'draft');
      });
    });
  });

  document.querySelectorAll('[data-wnq-template-catalog]').forEach(function(catalog) {
    catalog.querySelectorAll('[data-wnq-category-filter]').forEach(function(button) {
      button.addEventListener('click', function() {
        var category = button.getAttribute('data-wnq-category-filter') || 'all';
        catalog.querySelectorAll('[data-wnq-category-filter]').forEach(function(other) {
          other.classList.toggle('is-active', other === button);
        });
        catalog.querySelectorAll('[data-wnq-template-group]').forEach(function(group) {
          var visible = category === 'all' || group.getAttribute('data-wnq-template-group') === category;
          group.hidden = !visible;
        });
      });
    });
  });

  document.querySelectorAll('[data-wnq-wizard]').forEach(function(form) {
    var currentStep = 1;
    var maxStep = 6;
    var prevButton = form.querySelector('[data-wnq-prev]');
    var nextButton = form.querySelector('[data-wnq-next]');
    var advanced = form.querySelector('[data-wnq-advanced]');
    var advancedPayloadGenerated = false;

    function selectedPageType() {
      var input = form.querySelector('input[name="page_type"]:checked');
      return input ? input.value : 'home';
    }

    function selectedSections() {
      return Array.prototype.slice.call(form.querySelectorAll('.wnq-ai-section-card input:checked')).map(function(input) {
        return input.closest('.wnq-ai-section-card').querySelector('strong').textContent.trim();
      });
    }

    function syncSectionOrder() {
      var orderInput = form.querySelector('[name="section_order"]');
      var selectedCards = Array.prototype.slice.call(form.querySelectorAll('.wnq-ai-section-card')).filter(function(card) {
        var input = card.querySelector('input[name="section_template_keys[]"]');
        return input && input.checked && !input.disabled;
      });
      var bannerCard = selectedCards.find(function(card) {
        return card.getAttribute('data-section-category') === 'hero';
      });

      if (bannerCard && selectedCards[0] !== bannerCard) {
        bannerCard.parentNode.insertBefore(bannerCard, selectedCards[0]);
        selectedCards = Array.prototype.slice.call(form.querySelectorAll('.wnq-ai-section-card')).filter(function(card) {
          var input = card.querySelector('input[name="section_template_keys[]"]');
          return input && input.checked && !input.disabled;
        });
      }

      selectedCards.forEach(function(card, index) {
        var position = card.querySelector('[data-section-position]');
        var up = card.querySelector('[data-move-section="up"]');
        var down = card.querySelector('[data-move-section="down"]');
        var isBanner = card.getAttribute('data-section-category') === 'hero';
        if (position) position.textContent = isBanner ? '1 - Banner locked' : String(index + 1);
        if (up) up.disabled = isBanner || index === 0 || (index === 1 && bannerCard === selectedCards[0]);
        if (down) down.disabled = isBanner || index === selectedCards.length - 1;
      });

      form.querySelectorAll('.wnq-ai-section-card').forEach(function(card) {
        var input = card.querySelector('input[name="section_template_keys[]"]');
        var controls = card.querySelector('[data-section-order-controls]');
        if (controls) controls.hidden = !input || !input.checked || input.disabled;
      });

      if (orderInput) {
        orderInput.value = selectedCards.map(function(card) {
          var input = card.querySelector('input[name="section_template_keys[]"]');
          return input ? input.value : '';
        }).filter(Boolean).join(',');
      }
    }

    function updateImageSlots() {
      var type = selectedPageType();
      var sections = selectedSections();
      var templateKeys = Array.prototype.slice.call(form.querySelectorAll('input[name="section_template_keys[]"]:checked')).map(function(input) {
        return input.value;
      });
      form.querySelectorAll('[data-image-slot]').forEach(function(slot) {
        var key = slot.getAttribute('data-image-slot') || '';
        var show = key === 'logo_image_url' || key === 'featured_image_url';
        if (key === 'hero_background_image_url') show = sections.indexOf('Hero') !== -1 || ['home', 'service', 'city', 'service_city', 'ads'].indexOf(type) !== -1;
        if (key === 'content_image_url') show = sections.indexOf('Services') !== -1 || sections.indexOf('About') !== -1 || ['service', 'service_city', 'about'].indexOf(type) !== -1;
        if (key.indexOf('gallery_image_') === 0) show = sections.indexOf('Gallery') !== -1 || templateKeys.indexOf('gallery_section') !== -1;
        if (key === 'split_image_url') show = sections.indexOf('Image + Text') !== -1;
        if (key === 'before_image_url' || key === 'after_image_url') show = sections.indexOf('Gallery') !== -1 && ['service', 'service_city', 'ads'].indexOf(type) !== -1;
        if (key.indexOf('hero_slide_') === 0) show = templateKeys.indexOf('home_hero_section') !== -1;
        slot.hidden = !show;
      });
    }

    function updatePageFields() {
      var type = selectedPageType();
      var fieldRules = {
        city: ['city', 'service_city'],
        state: ['city', 'service_city'],
        service: ['home', 'service', 'city', 'service_city', 'ads'],
        main_offer: ['home', 'service', 'service_city', 'ads'],
        secondary_cta_text: ['home', 'service', 'city', 'service_city', 'about']
      };
      Object.keys(fieldRules).forEach(function(name) {
        var field = form.querySelector('[name="' + name + '"]');
        if (field && field.closest('label')) field.closest('label').hidden = fieldRules[name].indexOf(type) === -1;
      });
      form.querySelectorAll('[data-contact-only]').forEach(function(field) {
        field.hidden = type !== 'contact';
      });
    }

    function updateRequiredSections() {
      form.querySelectorAll('.wnq-ai-section-card input[data-recommended-pages]').forEach(function(input) {
        var recommended = (input.getAttribute('data-recommended-pages') || '').split(',');
        input.checked = !input.disabled && recommended.indexOf(selectedPageType()) !== -1;
      });
      syncSectionOrder();
    }

    function validateStep() {
      if (currentStep === 1) {
        var client = form.querySelector('[name="agent_key_id"]');
        if (client && !client.value) {
          client.focus();
          client.reportValidity();
          return false;
        }
      }
      return true;
    }

    function summaryValue(name, fallback) {
      var field = form.querySelector('[name="' + name + '"]');
      return field && field.value.trim() ? field.value.trim() : fallback;
    }

    function buildSummary() {
      var summary = form.querySelector('[data-wnq-summary]');
      if (!summary) return;
      var client = form.querySelector('[name="agent_key_id"]');
      var pageType = form.querySelector('input[name="page_type"]:checked');
      var uploads = Array.prototype.slice.call(form.querySelectorAll('[data-wnq-image-input]')).filter(function(input) { return input.files && input.files.length; }).map(function(input) { return input.closest('[data-image-slot]').querySelector('strong').textContent; });
      var rows = [
        ['Client site', client && client.selectedIndex > 0 ? client.options[client.selectedIndex].text : 'Not selected'],
        ['Page type', pageType ? pageType.closest('label').querySelector('strong').textContent : 'Home Page'],
        ['Page title', summaryValue('page_title', 'Uses main headline')],
        ['Primary service', summaryValue('service', 'Not entered')],
        ['Target city', summaryValue('city', 'Not entered')],
        ['Selected sections', selectedSections().join(', ') || 'No sections selected'],
        ['Required structure', selectedPageType() === 'contact' ? 'Top banner, Elementor Pro, contact iframe section' : 'Top banner and Elementor Pro'],
        ['Uploaded images', uploads.join(', ') || 'No images uploaded'],
        ['CTA text', summaryValue('primary_cta_text', 'Not entered')],
        ['SEO title', summaryValue('title_tag', 'Uses page title')],
        ['Meta description', summaryValue('meta_description', 'Not entered')]
      ];
      summary.innerHTML = rows.map(function(row) {
        return '<div><span>' + row[0] + '</span><strong>' + row[1].replace(/[&<>"']/g, function(char) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]; }) + '</strong></div>';
      }).join('');
    }

    function populateAdvancedPayload() {
      var textarea = form.querySelector('[name="variables_json"]');
      if (!textarea || (textarea.value.trim() && !advancedPayloadGenerated)) return;
      var payload = {};
      form.querySelectorAll('.wnq-ai-simple-fields input, .wnq-ai-simple-fields textarea, input[name="page_type"]:checked').forEach(function(field) {
        if (field.name && field.value) payload[field.name] = field.value;
      });
      textarea.value = JSON.stringify(payload, null, 2);
      advancedPayloadGenerated = true;
    }

    function showStep(step) {
      currentStep = Math.max(1, Math.min(maxStep, step));
      form.querySelectorAll('[data-wnq-step]').forEach(function(panel) {
        var active = parseInt(panel.getAttribute('data-wnq-step'), 10) === currentStep;
        panel.hidden = !active;
        panel.classList.toggle('is-active', active);
      });
      form.querySelectorAll('[data-wnq-step-nav]').forEach(function(button) {
        var buttonStep = parseInt(button.getAttribute('data-wnq-step-nav'), 10);
        button.classList.toggle('is-active', buttonStep === currentStep);
        button.classList.toggle('is-complete', buttonStep < currentStep);
      });
      prevButton.hidden = currentStep === 1 || currentStep === 6;
      nextButton.hidden = currentStep === 6;
      if (currentStep === 5) updateImageSlots();
      if (currentStep === 3) updatePageFields();
      if (currentStep === 6) buildSummary();
    }

    form.querySelectorAll('[data-wnq-step-nav]').forEach(function(button) {
      button.addEventListener('click', function() {
        var target = parseInt(button.getAttribute('data-wnq-step-nav'), 10);
        if (target <= currentStep || validateStep()) showStep(target);
      });
    });
    form.querySelectorAll('[data-wnq-go-step]').forEach(function(button) {
      button.addEventListener('click', function() { showStep(parseInt(button.getAttribute('data-wnq-go-step'), 10)); });
    });
    nextButton.addEventListener('click', function() { if (validateStep()) showStep(currentStep + 1); });
    prevButton.addEventListener('click', function() { showStep(currentStep - 1); });
    form.querySelectorAll('input[name="page_type"]').forEach(function(input) {
      input.addEventListener('change', function() {
        updateImageSlots();
        updatePageFields();
        updateRequiredSections();
      });
    });
    form.querySelectorAll('.wnq-ai-section-card input').forEach(function(input) {
      input.addEventListener('change', function() {
        syncSectionOrder();
        updateImageSlots();
        updatePageFields();
      });
    });
    form.querySelectorAll('[data-move-section]').forEach(function(button) {
      button.addEventListener('click', function() {
        var card = button.closest('.wnq-ai-section-card');
        if (!card || card.getAttribute('data-section-category') === 'hero') return;
        var selectedCards = Array.prototype.slice.call(form.querySelectorAll('.wnq-ai-section-card')).filter(function(item) {
          var input = item.querySelector('input[name="section_template_keys[]"]');
          return input && input.checked && !input.disabled;
        });
        var index = selectedCards.indexOf(card);
        var target = button.getAttribute('data-move-section') === 'up' ? selectedCards[index - 1] : selectedCards[index + 1];
        if (!target || target.getAttribute('data-section-category') === 'hero') return;
        if (button.getAttribute('data-move-section') === 'up') {
          card.parentNode.insertBefore(card, target);
        } else {
          card.parentNode.insertBefore(card, target.nextSibling);
        }
        syncSectionOrder();
      });
    });
    var clientSelect = form.querySelector('[name="agent_key_id"]');
    if (clientSelect) {
      clientSelect.addEventListener('change', function() {
        var option = clientSelect.options[clientSelect.selectedIndex];
        var autofill = {
          business_name: option ? option.getAttribute('data-client-name') : '',
          phone_number: option ? option.getAttribute('data-client-phone') : '',
          website_url: option ? option.getAttribute('data-client-website') : '',
          service: option ? option.getAttribute('data-client-services') : ''
        };
        Object.keys(autofill).forEach(function(name) {
          var field = form.querySelector('[name="' + name + '"]');
          if (field && !field.value && autofill[name]) field.value = autofill[name];
        });
        if (advancedPayloadGenerated) populateAdvancedPayload();
      });
    }
    form.querySelectorAll('[data-wnq-image-input]').forEach(function(input) {
      function updatePreview() {
        var preview = input.closest('.wnq-ai-upload-box').querySelector('.wnq-ai-upload-preview');
        if (!input.files || !input.files[0]) return;
        var reader = new FileReader();
        reader.onload = function(event) {
          preview.style.backgroundImage = 'url("' + event.target.result + '")';
          preview.classList.add('has-image');
          preview.textContent = '';
        };
        reader.readAsDataURL(input.files[0]);
      }
      input.addEventListener('change', updatePreview);
      var box = input.closest('.wnq-ai-upload-box');
      box.addEventListener('dragover', function(event) { event.preventDefault(); box.classList.add('is-dragging'); });
      box.addEventListener('dragleave', function() { box.classList.remove('is-dragging'); });
      box.addEventListener('drop', function(event) {
        event.preventDefault();
        box.classList.remove('is-dragging');
        if (event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length) {
          input.files = event.dataTransfer.files;
          updatePreview();
        }
      });
    });
    document.querySelectorAll('[data-wnq-open-advanced]').forEach(function(button) {
      button.addEventListener('click', function() {
        populateAdvancedPayload();
        advanced.open = true;
        advanced.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    });
    advanced.addEventListener('toggle', function() { if (advanced.open) populateAdvancedPayload(); });
    var advancedPayload = form.querySelector('[name="variables_json"]');
    if (advancedPayload) advancedPayload.addEventListener('input', function() { advancedPayloadGenerated = false; });
    form.querySelectorAll('.wnq-ai-simple-fields input, .wnq-ai-simple-fields textarea, input[name="page_type"]').forEach(function(field) {
      field.addEventListener('input', function() { if (advancedPayloadGenerated) populateAdvancedPayload(); });
      field.addEventListener('change', function() { if (advancedPayloadGenerated) populateAdvancedPayload(); });
    });
    syncSectionOrder();
    updatePageFields();
    showStep(1);
  });
})();
</script>

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
  .wnq-ai-tab-shell {
    display: grid;
    gap: 16px;
  }
  .wnq-ai-tabs {
    background: rgba(0, 0, 0, 0.22);
    border: 1px solid var(--ai-line);
    border-radius: 18px;
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    padding: 8px;
    position: sticky;
    top: 32px;
    z-index: 5;
  }
  .wnq-ai-tab {
    appearance: none;
    background: transparent;
    border: 1px solid transparent;
    border-radius: 14px;
    color: var(--ai-muted);
    cursor: pointer;
    display: grid;
    gap: 3px;
    min-height: 72px;
    padding: 12px 14px;
    text-align: left;
    transition: border-color .18s ease, background .18s ease, color .18s ease, transform .18s ease;
  }
  .wnq-ai-tab span {
    color: var(--ai-gold-2);
    font-size: 12px;
    font-weight: 900;
    letter-spacing: .12em;
  }
  .wnq-ai-tab strong {
    color: inherit;
    font-size: 15px;
    line-height: 1.2;
  }
  .wnq-ai-tab small {
    color: var(--ai-muted-2);
    font-size: 12px;
    line-height: 1.25;
  }
  .wnq-ai-tab:hover,
  .wnq-ai-tab:focus {
    border-color: var(--ai-line-strong);
    color: var(--ai-text);
    outline: none;
  }
  .wnq-ai-tab.is-active {
    background: linear-gradient(135deg, rgba(217, 190, 66, 0.18), rgba(217, 190, 66, 0.06));
    border-color: var(--ai-line-strong);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.05), 0 12px 28px rgba(0,0,0,.2);
    color: var(--ai-text);
  }
  .wnq-ai-tab-panel[hidden] {
    display: none !important;
  }
  .wnq-ai-tab-panel.is-active {
    animation: wnqAiPanelIn .16s ease-out;
    display: block;
  }
  @keyframes wnqAiPanelIn {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
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
  .wnq-ai-builder-page input[type="url"],
  .wnq-ai-builder-page input[type="color"],
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
  .wnq-ai-builder-page input[type="url"]:focus,
  .wnq-ai-builder-page input[type="color"]:focus,
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
  .wnq-ai-category-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 12px 0 14px;
  }
  .wnq-ai-category-filters button {
    appearance: none;
    background: rgba(255,255,255,.035);
    border: 1px solid rgba(255,255,255,.08);
    border-radius: 999px;
    color: var(--ai-muted);
    cursor: pointer;
    font-size: 12px;
    font-weight: 800;
    padding: 7px 11px;
  }
  .wnq-ai-category-filters button:hover,
  .wnq-ai-category-filters button:focus,
  .wnq-ai-category-filters button.is-active {
    background: rgba(217,190,66,.13);
    border-color: var(--ai-line-strong);
    color: var(--ai-gold-2);
    outline: none;
  }
  .wnq-ai-template-groups {
    display: grid;
    gap: 12px;
  }
  .wnq-ai-elementor-template-source .wnq-ai-template-groups {
    max-height: min(540px, 58vh);
    overflow: auto;
    padding-right: 4px;
  }
  .wnq-ai-template-group {
    background: rgba(255, 255, 255, 0.02);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    overflow: hidden;
  }
  .wnq-ai-template-group > summary {
    align-items: center;
    background: rgba(255, 255, 255, 0.025);
    display: flex;
    justify-content: space-between;
  }
  .wnq-ai-template-group > summary em {
    background: rgba(217,190,66,.13);
    border: 1px solid rgba(217,190,66,.22);
    border-radius: 999px;
    color: var(--ai-gold-2);
    font-style: normal;
    font-weight: 900;
    min-width: 28px;
    padding: 3px 8px;
    text-align: center;
  }
  .wnq-ai-template-group .wnq-ai-elementor-section-list,
  .wnq-ai-template-group .wnq-ai-template-library-list {
    margin: 0;
    padding: 12px;
  }
  .wnq-ai-section-catalog {
    margin-top: 10px;
  }
  .wnq-ai-section-option > span {
    display: grid;
    gap: 3px;
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
  .wnq-ai-template-guidance {
    align-items: flex-start;
    background: rgba(217, 190, 66, 0.07);
    border-left: 3px solid var(--ai-gold);
    display: flex;
    gap: 14px;
    margin: 0 0 24px;
    padding: 14px 16px;
  }
  .wnq-ai-template-guidance strong {
    color: var(--ai-gold-2);
    flex: 0 0 auto;
  }
  .wnq-ai-template-guidance span {
    color: var(--ai-muted);
  }
  .wnq-ai-template-guidance code {
    color: var(--ai-gold-2);
  }
  .wnq-ai-variable-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
  }
  .wnq-ai-mini-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-top: 6px;
  }
  .wnq-ai-variable-chips code,
  .wnq-ai-mini-chips code,
  .wnq-ai-elementor-image-grid code,
  .wnq-ai-builder-page code {
    background: rgba(217, 190, 66, 0.12);
    border: 1px solid rgba(217, 190, 66, 0.18);
    border-radius: 999px;
    color: var(--ai-gold-2);
    padding: 4px 8px;
  }
  .wnq-ai-placeholder-warning {
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.28);
    border-radius: 999px;
    color: #f5c96a;
    font-size: 12px;
    font-weight: 800;
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
  .wnq-ai-builder-stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .wnq-ai-builder-stats .wnq-ai-stat-title {
    font-size: 16px;
    line-height: 1.25;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .wnq-ai-success-state {
    align-items: flex-start;
    background: linear-gradient(135deg, rgba(217,190,66,.17), rgba(255,255,255,.04)), #101314;
    border: 1px solid rgba(217,190,66,.42);
    border-radius: 18px;
    color: #f6f2df;
    display: flex;
    gap: 18px;
    margin: 18px 0;
    padding: 22px;
  }
  .wnq-ai-success-state h2 { color: #f6f2df; margin: 2px 0 14px; }
  .wnq-ai-success-icon {
    align-items: center;
    background: #d9be42;
    border-radius: 50%;
    color: #161100;
    display: flex;
    flex: 0 0 auto;
    font-size: 24px;
    font-weight: 900;
    height: 48px;
    justify-content: center;
    width: 48px;
  }
  .wnq-ai-success-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px 28px;
    margin: 0;
  }
  .wnq-ai-success-meta dt { color: #a6acb3; font-size: 11px; font-weight: 800; text-transform: uppercase; }
  .wnq-ai-success-meta dd { font-weight: 800; margin: 3px 0 0; }
  .wnq-ai-success-actions { display: flex; flex-wrap: wrap; gap: 10px; margin: 16px 0 0; }
  .wnq-ai-builder-heading {
    align-items: flex-start;
    border-bottom: 1px solid rgba(217,190,66,.12);
    display: flex;
    gap: 20px;
    justify-content: space-between;
    margin-bottom: 18px;
    padding-bottom: 18px;
  }
  .wnq-ai-builder-heading h2 { color: var(--ai-text); font-size: 28px; margin: 0; }
  .wnq-ai-builder-heading p { color: var(--ai-muted); margin: 7px 0 0; max-width: 720px; }
  .wnq-ai-wizard-progress {
    display: grid;
    gap: 6px;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    margin-bottom: 24px;
  }
  .wnq-ai-wizard-progress button {
    appearance: none;
    background: rgba(255,255,255,.025);
    border: 0;
    border-top: 3px solid rgba(255,255,255,.10);
    color: var(--ai-muted-2);
    cursor: pointer;
    font-size: 11px;
    font-weight: 800;
    padding: 11px 6px 8px;
    text-align: left;
  }
  .wnq-ai-wizard-progress button span {
    align-items: center;
    background: rgba(255,255,255,.07);
    border-radius: 50%;
    display: inline-flex;
    height: 22px;
    justify-content: center;
    margin-right: 5px;
    width: 22px;
  }
  .wnq-ai-wizard-progress button.is-active { border-color: var(--ai-gold); color: var(--ai-text); }
  .wnq-ai-wizard-progress button.is-complete { border-color: rgba(217,190,66,.46); color: var(--ai-gold-2); }
  .wnq-ai-wizard-step {
    animation: wnqAiPanelIn .16s ease-out;
    min-height: 420px;
    padding: 6px 2px;
  }
  .wnq-ai-wizard-step[hidden] { display: none !important; }
  .wnq-ai-step-heading { margin-bottom: 22px; }
  .wnq-ai-step-heading > span { color: var(--ai-gold-2); font-size: 11px; font-weight: 900; letter-spacing: .12em; text-transform: uppercase; }
  .wnq-ai-step-heading h3 { color: var(--ai-text); font-size: 25px; margin: 5px 0 6px; }
  .wnq-ai-step-heading p { color: var(--ai-muted); margin: 0; }
  .wnq-ai-client-picker {
    background: var(--ai-panel-2);
    border: 1px solid var(--ai-line-strong);
    border-radius: 16px;
    max-width: 760px;
    padding: 20px;
  }
  .wnq-ai-client-picker select { font-size: 16px; min-height: 52px; }
  .wnq-ai-autofill-note {
    align-items: flex-start;
    background: rgba(217,190,66,.07);
    border-left: 3px solid var(--ai-gold);
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 18px;
    max-width: 760px;
    padding: 13px 16px;
  }
  .wnq-ai-autofill-note span { color: var(--ai-muted); }
  .wnq-ai-page-type-grid,
  .wnq-ai-section-card-grid {
    display: grid;
    gap: 12px;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  }
  .wnq-ai-page-type-grid label,
  .wnq-ai-section-card {
    background: var(--ai-panel-2);
    border: 1px solid rgba(255,255,255,.09);
    border-radius: 14px;
    min-height: 108px;
    padding: 16px;
    transition: border-color .16s ease, background .16s ease, transform .16s ease;
  }
  .wnq-ai-page-type-grid label {
    cursor: pointer;
    display: flex;
    gap: 12px;
  }
  .wnq-ai-section-card-select {
    cursor: pointer;
    display: flex;
    gap: 12px;
  }
  .wnq-ai-page-type-grid label:hover,
  .wnq-ai-section-card:hover { border-color: var(--ai-line-strong); transform: translateY(-1px); }
  .wnq-ai-page-type-grid label:has(input:checked),
  .wnq-ai-section-card:has(input:checked) { background: rgba(217,190,66,.11); border-color: var(--ai-gold); }
  .wnq-ai-page-type-grid input,
  .wnq-ai-section-card input { accent-color: var(--ai-gold); margin-top: 3px; }
  .wnq-ai-page-type-grid span,
  .wnq-ai-section-card-select > span { display: grid; gap: 7px; }
  .wnq-ai-page-type-grid small,
  .wnq-ai-section-card small { color: var(--ai-muted); line-height: 1.4; }
  .wnq-ai-section-card.is-unavailable { cursor: default; opacity: .52; }
  .wnq-ai-section-card-top { align-items: center; display: flex; gap: 8px; justify-content: space-between; }
  .wnq-ai-section-card-top em {
    background: rgba(217,190,66,.12);
    border: 1px solid rgba(217,190,66,.20);
    border-radius: 999px;
    color: var(--ai-gold-2);
    font-size: 10px;
    font-style: normal;
    font-weight: 900;
    padding: 3px 7px;
    text-transform: uppercase;
  }
  .wnq-ai-section-order-controls {
    align-items: center;
    border-top: 1px solid rgba(255,255,255,.08);
    display: flex;
    gap: 7px;
    margin-top: 14px;
    padding-top: 12px;
  }
  .wnq-ai-section-order-controls[hidden] { display: none; }
  .wnq-ai-section-order-controls span {
    color: var(--ai-gold-2);
    font-size: 11px;
    font-weight: 900;
    margin-right: auto;
    text-transform: uppercase;
  }
  .wnq-ai-section-order-controls button {
    align-items: center;
    background: rgba(217,190,66,.08);
    border: 1px solid var(--ai-line-strong);
    border-radius: 6px;
    color: var(--ai-gold-2);
    cursor: pointer;
    display: inline-flex;
    font-size: 16px;
    height: 30px;
    justify-content: center;
    padding: 0;
    width: 32px;
  }
  .wnq-ai-section-order-controls button:hover:not(:disabled) { background: rgba(217,190,66,.18); border-color: var(--ai-gold); }
  .wnq-ai-section-order-controls button:disabled { cursor: not-allowed; opacity: .3; }
  .wnq-ai-simple-fields {
    display: grid;
    gap: 14px;
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
  .wnq-ai-simple-fields label { display: block; }
  .wnq-ai-simple-fields .wnq-ai-field-wide { grid-column: span 3; }
  .wnq-ai-upload-box {
    align-items: center;
    border: 1px dashed var(--ai-line-strong) !important;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    min-height: 205px;
    text-align: center;
  }
  .wnq-ai-upload-box[hidden] { display: none !important; }
  .wnq-ai-upload-box.is-dragging { background: rgba(217,190,66,.10); border-color: var(--ai-gold) !important; }
  .wnq-ai-upload-preview {
    align-items: center;
    background: rgba(217,190,66,.10);
    background-position: center;
    background-size: cover;
    border-radius: 10px;
    color: var(--ai-gold-2);
    display: flex;
    font-size: 32px;
    height: 100px;
    justify-content: center;
    margin-bottom: 10px;
    width: 100%;
  }
  .wnq-ai-upload-preview.has-image { border: 1px solid rgba(217,190,66,.35); }
  .wnq-ai-upload-box input[type=file] { border: 0; font-size: 11px; padding: 8px 0 0; }
  .wnq-ai-summary-card {
    background: var(--ai-panel-2);
    border: 1px solid var(--ai-line);
    border-radius: 16px;
    display: grid;
    gap: 0;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    overflow: hidden;
  }
  .wnq-ai-summary-card div { border-bottom: 1px solid rgba(255,255,255,.07); display: grid; gap: 4px; padding: 14px 16px; }
  .wnq-ai-summary-card div:nth-child(odd) { border-right: 1px solid rgba(255,255,255,.07); }
  .wnq-ai-summary-card span { color: var(--ai-muted); font-size: 11px; font-weight: 800; text-transform: uppercase; }
  .wnq-ai-summary-card strong { color: var(--ai-text); }
  .wnq-ai-confirmation-actions,
  .wnq-ai-wizard-actions { display: flex; gap: 10px; justify-content: space-between; margin-top: 20px; }
  .wnq-ai-advanced-mode { margin-top: 28px; }
  .wnq-ai-advanced-mode > summary { background: rgba(0,0,0,.20); }
  .wnq-ai-advanced-mode > summary span { display: grid; gap: 3px; }
  .wnq-ai-advanced-mode > summary small { color: var(--ai-muted); }
  .wnq-ai-advanced-body { padding: 16px; }
  @media (max-width: 1180px) {
    .wnq-ai-builder-hero,
    .wnq-ai-workflow-grid {
      grid-template-columns: 1fr;
    }
    .wnq-ai-simple-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .wnq-ai-simple-fields .wnq-ai-field-wide { grid-column: span 2; }
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
    .wnq-ai-tabs {
      grid-template-columns: 1fr;
      position: static;
    }
    .wnq-ai-builder-heading,
    .wnq-ai-success-state { display: grid; }
    .wnq-ai-wizard-progress { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .wnq-ai-wizard-step { min-height: 0; }
    .wnq-ai-simple-fields,
    .wnq-ai-summary-card { grid-template-columns: 1fr; }
    .wnq-ai-simple-fields .wnq-ai-field-wide { grid-column: auto; }
    .wnq-ai-summary-card div:nth-child(odd) { border-right: 0; }
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
        $section_template_keys = array_values(array_unique(array_filter($section_template_keys)));
        $section_order = array_values(array_unique(array_filter(array_map(
            'sanitize_key',
            explode(',', (string)wp_unslash($_POST['section_order'] ?? ''))
        ))));
        if ($section_order) {
            $ordered_selected = array_values(array_intersect($section_order, $section_template_keys));
            $section_template_keys = array_values(array_merge(
                $ordered_selected,
                array_diff($section_template_keys, $ordered_selected)
            ));
        }
        $template_json = '';
        $variables_json = trim(self::readTextInputOrFile('variables_json', 'variables_json_file'));
        $variables = self::simpleModeVariables();
        if ($variables_json !== '') {
            $advanced_variables = json_decode($variables_json, true);
            if (!is_array($advanced_variables)) {
                self::redirectWithError('Invalid variable JSON: ' . json_last_error_msg());
            }
            $variables = array_merge($variables, $advanced_variables);
        }
        $required_defaults = ElementorSectionLibrary::defaults(ElementorSectionLibrary::TOP_BANNER);
        if (($variables['page_type'] ?? '') === 'contact') {
            $required_defaults = array_merge(
                $required_defaults,
                ElementorSectionLibrary::defaults(ElementorSectionLibrary::CONTACT_IFRAME)
            );
        }
        $variables = array_merge($required_defaults, $variables);
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

        $contact_iframe = $variables['contact_form_iframe'] ?? '';
        if (
            ($variables['page_type'] ?? '') === 'contact'
            && (!is_string($contact_iframe) || !preg_match('/<iframe[\s>]/i', $contact_iframe))
            && !self::templateContainsEmbeddedIframe($template_json)
        ) {
            self::redirectWithError('Contact pages require a contact form iframe embed code.');
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
        self::recordGeneratedDraft($result);

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
            'notice' => !empty($result['warning']) ? 'template_saved_no_variables' : 'template_saved',
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
        $generation_vars = [
            'business_name'   => sanitize_text_field(wp_unslash($_POST['ai_business_name'] ?? '')),
            'brand_notes'     => sanitize_textarea_field(wp_unslash($_POST['ai_brand_notes'] ?? '')),
            'service'         => sanitize_text_field(wp_unslash($_POST['ai_service'] ?? '')),
            'city'            => sanitize_text_field(wp_unslash($_POST['ai_city'] ?? '')),
            'state'           => sanitize_text_field(wp_unslash($_POST['ai_state'] ?? '')),
            'audience'        => 'local service business customers',
            'page_goal'       => sanitize_text_field(wp_unslash($_POST['ai_page_goal'] ?? '')),
            'tone'            => sanitize_text_field(wp_unslash($_POST['ai_tone'] ?? 'professional, clear, conversion-focused')),
            'theme_style'     => sanitize_text_field(wp_unslash($_POST['ai_theme_style'] ?? '')),
            'section_context' => ElementorSectionLibrary::writingContextFor($keys),
            'variables'       => implode("\n", array_map(static fn($key) => '- ' . $key, $variables)),
            'image_variables' => $image_variables ? implode("\n", array_map(static fn($key) => '- ' . $key, $image_variables)) : 'None',
            'quality_feedback' => 'No previous draft. Write a complete, varied payload that passes all quality rules.',
            'previous_payload' => 'None',
        ];

        $json = '';
        $quality_issues = [];
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $result = AIEngine::generate('elementor_variable_payload', $generation_vars, '', [
                'max_tokens'  => 5000,
                'temperature' => $attempt === 1 ? 0.45 : 0.35,
                'no_cache'    => true,
            ]);

            if (empty($result['success'])) {
                self::redirectWithError('AI variable generation failed: ' . (string)($result['error'] ?? 'unknown error'));
            }

            $json = self::extractJsonObject((string)($result['content'] ?? ''));
            if ($json === '') {
                $generation_vars['quality_feedback'] = 'The previous response was not valid JSON. Return one complete valid JSON object containing every requested variable.';
                continue;
            }

            $payload = json_decode($json, true);
            $quality_issues = is_array($payload)
                ? self::payloadQualityIssues($payload, $variables, $image_variables)
                : ['Return one complete valid JSON object.'];
            if (!$quality_issues) {
                break;
            }

            $feedback_issues = array_slice($quality_issues, 0, 25);
            $generation_vars['quality_feedback'] = implode("\n", array_map(static fn($issue) => '- ' . $issue, $feedback_issues));
            $generation_vars['previous_payload'] = $json;
        }

        if ($json === '') {
            self::redirectWithError('AI did not return a valid JSON object after multiple attempts. Try again or check AI settings.');
        }

        set_transient(self::aiPayloadTransientKey(), [
            'json'     => $json,
            'warnings' => $quality_issues,
        ], 10 * MINUTE_IN_SECONDS);

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

    private static function templateContainsEmbeddedIframe(string $template_json): bool
    {
        $decoded = json_decode($template_json, true);
        return self::valueContainsContactIframe(is_array($decoded) ? $decoded : $template_json);
    }

    private static function valueContainsContactIframe($value): bool
    {
        if (is_array($value)) {
            foreach ($value as $child) {
                if (self::valueContainsContactIframe($child)) {
                    return true;
                }
            }

            return false;
        }
        if (!is_string($value) || !preg_match_all('/<iframe\b[^>]*>/i', $value, $matches)) {
            return false;
        }

        foreach ($matches[0] as $iframe) {
            $iframe = strtolower((string)$iframe);
            foreach (['form', 'contact', 'quote', 'booking', 'appointment', 'leadconnector', 'msgsndr', 'jotform', 'typeform', 'formstack', 'wufoo', 'hubspot', 'calendly'] as $marker) {
                if (strpos($iframe, $marker) !== false) {
                    return true;
                }
            }
        }

        return false;
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

    private static function simpleModeVariables(): array
    {
        $text_fields = [
            'page_type', 'page_title', 'business_name', 'service', 'city', 'state',
            'h1', 'hero_subheadline', 'primary_cta_text', 'secondary_cta_text',
            'phone_number', 'business_email', 'business_address', 'website_url', 'main_offer', 'accent_color', 'hero_background_color',
            'tone_of_voice', 'title_tag', 'primary_keyword',
        ];
        $textarea_fields = ['short_description', 'meta_description'];
        $variables = [];

        foreach ($text_fields as $field) {
            $variables[$field] = sanitize_text_field(wp_unslash($_POST[$field] ?? ''));
        }
        foreach ($textarea_fields as $field) {
            $variables[$field] = sanitize_textarea_field(wp_unslash($_POST[$field] ?? ''));
        }
        $variables['business_email'] = sanitize_email((string)$variables['business_email']);
        $variables['contact_form_iframe'] = self::sanitizeContactIframe(wp_unslash((string)($_POST['contact_form_iframe'] ?? '')));
        $variables['page_type'] = array_key_exists($variables['page_type'], self::pageTypes()) ? $variables['page_type'] : 'custom';
        $variables['website_url'] = esc_url_raw((string)$variables['website_url']);
        $variables['accent_color'] = sanitize_hex_color((string)$variables['accent_color']) ?: '';
        $variables['hero_background_color'] = sanitize_hex_color((string)$variables['hero_background_color']) ?: '';

        if ($variables['primary_keyword'] === '') {
            $variables['primary_keyword'] = trim($variables['service'] . ' ' . $variables['city']);
        }
        if ($variables['h1'] === '') {
            $variables['h1'] = $variables['page_title'];
        }
        if ($variables['title_tag'] === '') {
            $variables['title_tag'] = $variables['page_title'];
        }
        if ($variables['hero_subheadline'] === '') {
            $variables['hero_subheadline'] = $variables['short_description'];
        }
        if ($variables['city'] !== '') {
            $variables['hero_highlighted_text'] = $variables['city'] . ($variables['state'] !== '' ? ', ' . $variables['state'] : '');
            $variables['service_area_heading'] = 'Serving ' . $variables['city'] . ' and Surrounding Areas';
        }
        if ($variables['short_description'] !== '') {
            $variables['service_area_copy'] = $variables['short_description'];
            $variables['split_content_copy'] = $variables['short_description'];
        }
        if ($variables['service'] !== '') {
            $variables['split_eyebrow'] = $variables['service'];
            $variables['split_heading'] = 'More About ' . $variables['service'];
            $variables['split_highlight'] = 'Services';
        }
        if ($variables['h1'] !== '') {
            $variables['split_image_alt'] = $variables['h1'];
        }

        return array_filter($variables, static fn($value) => $value !== '');
    }

    private static function sanitizeContactIframe(string $value): string
    {
        return wp_kses($value, [
            'iframe' => [
                'src'             => true,
                'title'           => true,
                'id'              => true,
                'class'           => true,
                'name'            => true,
                'width'           => true,
                'height'          => true,
                'style'           => true,
                'frameborder'     => true,
                'scrolling'       => true,
                'allowtransparency' => true,
                'allow'           => true,
                'allowfullscreen' => true,
                'loading'         => true,
                'referrerpolicy'  => true,
                'sandbox'         => true,
            ],
        ]);
    }

    private static function recordGeneratedDraft(array $result): void
    {
        $stats = get_option('wnq_ai_elementor_builder_stats', []);
        $month = current_time('Y-m');
        $stats_month = sanitize_text_field((string)($stats['month'] ?? ''));
        $stats['month'] = $month;
        $stats['month_count'] = $stats_month === $month ? absint($stats['month_count'] ?? 0) + 1 : 1;
        $stats['last_title'] = sanitize_text_field((string)($result['post_title'] ?? 'Elementor Draft'));
        $stats['last_generated_at'] = current_time('mysql');
        update_option('wnq_ai_elementor_builder_stats', $stats, false);
    }

    private static function pageTypes(): array
    {
        return [
            'home'         => ['label' => 'Home Page', 'description' => 'Primary brand and conversion page'],
            'service'      => ['label' => 'Service Page', 'description' => 'Focused page for one service'],
            'city'         => ['label' => 'City Page', 'description' => 'SEO page for one service area'],
            'service_city' => ['label' => 'Service + City Page', 'description' => 'Local SEO service landing page'],
            'ads'          => ['label' => 'Google Ads Landing Page', 'description' => 'Focused campaign conversion page'],
            'about'        => ['label' => 'About Page', 'description' => 'Company story, trust, and team'],
            'contact'      => ['label' => 'Contact Page', 'description' => 'Calls, forms, and location details'],
            'blog'         => ['label' => 'Blog Page', 'description' => 'Dynamic archive of published WordPress posts'],
            'custom'       => ['label' => 'Custom Page', 'description' => 'Start with your own structure'],
        ];
    }

    private static function renderSimpleFields(): void
    {
        $fields = [
            ['page_title', 'Page Title', 'text', 'Plumbing Services in Orlando'],
            ['business_name', 'Business Name', 'text', 'PrimeFlow Plumbing'],
            ['service', 'Primary Service', 'text', 'Emergency Plumbing'],
            ['city', 'Target City', 'text', 'Orlando'],
            ['state', 'State', 'text', 'FL'],
            ['h1', 'Main Headline', 'text', 'Plumbing Done Right.'],
            ['hero_subheadline', 'Subheadline', 'text', 'Fast. Reliable. Professional.'],
            ['primary_cta_text', 'CTA Button Text', 'text', 'Schedule Service'],
            ['secondary_cta_text', 'Secondary CTA Button Text', 'text', 'View Services'],
            ['phone_number', 'Phone Number', 'text', '(555) 123-4567'],
            ['business_email', 'Business Email', 'email', 'hello@example.com'],
            ['business_address', 'Business Address', 'text', '123 Main Street, Orlando, FL'],
            ['website_url', 'Website URL', 'url', 'https://example.com'],
            ['main_offer', 'Main Offer', 'text', 'Same-day service'],
            ['accent_color', 'Primary Brand Color', 'color', '#d9be42'],
            ['hero_background_color', 'Secondary Brand Color', 'color', '#07131c'],
            ['tone_of_voice', 'Tone of Voice', 'text', 'Professional, clear, conversion-focused'],
            ['title_tag', 'SEO Title', 'text', 'Emergency Plumber in Orlando | PrimeFlow'],
            ['primary_keyword', 'Primary SEO Keyword', 'text', 'emergency plumber Orlando'],
        ];

        foreach ($fields as [$name, $label, $type, $placeholder]) {
            $value = $type === 'color' ? $placeholder : '';
            echo '<label><strong>' . esc_html($label) . '</strong><input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" placeholder="' . esc_attr($placeholder) . '" value="' . esc_attr($value) . '" data-wnq-field-label="' . esc_attr($label) . '"></label>';
        }
        echo '<label class="wnq-ai-field-wide"><strong>Short Description</strong><textarea name="short_description" rows="4" placeholder="Briefly describe the business, service, and value proposition." data-wnq-field-label="Short Description"></textarea></label>';
        echo '<label class="wnq-ai-field-wide"><strong>Meta Description</strong><textarea name="meta_description" rows="3" placeholder="Search-friendly summary for the page." data-wnq-field-label="Meta Description"></textarea></label>';
        echo '<label class="wnq-ai-field-wide" data-contact-only hidden><strong>Contact Form Iframe</strong><textarea name="contact_form_iframe" rows="5" placeholder=\'<iframe src="https://forms.example.com/contact" title="Contact form"></iframe>\' data-wnq-field-label="Contact Form Iframe"></textarea><span class="description">Required for Contact Pages. Paste the complete iframe embed code.</span></label>';
    }

    private static function renderSimpleSectionCards(array $templates): void
    {
        $section_cards = [
            'hero' => ['Hero', 'Headline, offer, background image, and primary CTA', 'Recommended', 'home,service,city,service_city,ads,about,contact'],
            'trust' => ['Trust Badges', 'Licenses, guarantees, certifications, or proof points', 'Recommended', 'home,service,city,service_city,ads'],
            'services' => ['Services', 'Scannable overview of core services', 'Recommended', 'home,service,city,service_city'],
            'about' => ['About', 'Company story and differentiators', '', 'home,about'],
            'process' => ['Process', 'Simple step-by-step customer journey', '', 'home,service,service_city'],
            'gallery' => ['Gallery', 'Project, team, or service imagery', '', 'home,service'],
            'service_area' => ['Service Areas', 'Local SEO introduction to cities and surrounding communities served', 'Recommended', 'home,city,service_city'],
            'content_split' => ['Image + Text', 'Supporting copy and bullet points paired with a service image', '', 'home,service,city,service_city,about'],
            'blog' => ['Blog Posts', 'Dynamic grid of existing WordPress posts', 'Blog Page', 'blog'],
            'reviews' => ['Reviews', 'Customer testimonials and ratings', 'Recommended', 'home,service,city,service_city'],
            'faq' => ['FAQ', 'Common questions and helpful answers', 'Recommended', 'home,service,city,service_city,contact'],
            'cta' => ['Final CTA', 'Closing offer and action prompt', 'Recommended', 'home,service,city,service_city,ads'],
            'contact_form' => ['Contact Form Alternative', 'Optional alternative contact form and supporting content', '', ''],
            'contact_details' => ['Contact Details', 'Phone, address, and email cards', 'Contact Page', 'contact'],
            'map' => ['Location Map', 'Google map showing the business address', 'Home + Contact', 'home,contact'],
        ];
        $template_by_category = [];
        foreach ($templates as $key => $template) {
            $category = self::templateCategoryKey((string)($template['category'] ?? ''), (string)$key, (string)($template['label'] ?? ''), (string)($template['description'] ?? ''));
            $template_by_category[$category] ??= (string)$key;
        }
        $template_by_category['services'] ??= $template_by_category['content'] ?? '';
        $template_by_category['about'] ??= $template_by_category['content'] ?? '';
        $template_by_category['contact_form'] ??= $template_by_category['contact'] ?? '';

        echo '<input type="hidden" name="section_order" value="">';
        echo '<div class="wnq-ai-section-card-grid" data-section-order-list>';
        foreach ($section_cards as $category => [$label, $description, $badge, $recommended_pages]) {
            $template_key = $template_by_category[$category] ?? '';
            $available = $template_key !== '';
            $recommended = $available && in_array('home', explode(',', $recommended_pages), true);
            echo '<div class="wnq-ai-section-card' . ($available ? '' : ' is-unavailable') . '" data-section-category="' . esc_attr($category) . '">';
            echo '<label class="wnq-ai-section-card-select"><input type="checkbox" name="section_template_keys[]" data-section-category="' . esc_attr($category) . '" data-recommended-pages="' . esc_attr($recommended_pages) . '" value="' . esc_attr($template_key) . '"' . checked($recommended, true, false) . ($available ? '' : ' disabled') . '>';
            echo '<span><span class="wnq-ai-section-card-top"><strong>' . esc_html($label) . '</strong>';
            if ($badge !== '') {
                echo '<em>' . esc_html($available ? $badge : 'Template needed') . '</em>';
            } elseif (!$available) {
                echo '<em>Template needed</em>';
            }
            echo '</span><small>' . esc_html($description) . '</small><small>' . esc_html($available ? 'Reusable Elementor template' : 'Add this section in Template Library') . '</small></span></label>';
            echo '<div class="wnq-ai-section-order-controls" data-section-order-controls hidden><span data-section-position></span><button type="button" data-move-section="up" aria-label="Move ' . esc_attr($label) . ' up" title="Move section up">&uarr;</button><button type="button" data-move-section="down" aria-label="Move ' . esc_attr($label) . ' down" title="Move section down">&darr;</button></div>';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function templateCategoryLabels(): array
    {
        return [
            'required'=> 'Required Sections',
            'header'  => 'Header Templates',
            'hero'    => 'Hero Templates',
            'content' => 'Content Sections',
            'cta'     => 'CTA Sections',
            'faq'     => 'FAQ Templates',
            'reviews' => 'Reviews Sections',
            'process' => 'Process Sections',
            'gallery' => 'Gallery Sections',
            'service_area' => 'Service Area Sections',
            'content_split' => 'Image + Text Sections',
            'blog' => 'Blog Sections',
            'contact' => 'Contact Sections',
            'contact_form' => 'Contact Form Sections',
            'contact_details' => 'Contact Detail Sections',
            'map'     => 'Map Sections',
            'footer'  => 'Footer Templates',
            'custom'  => 'Custom / Saved Templates',
            'other'   => 'Other Templates',
        ];
    }

    private static function groupSectionTemplates(array $templates): array
    {
        $groups = [];
        foreach (self::templateCategoryLabels() as $key => $label) {
            $groups[$key] = [
                'label' => $label,
                'items' => [],
            ];
        }

        foreach ($templates as $key => $template) {
            $group_key = self::templateCategoryKey(
                (string)($template['category'] ?? ''),
                (string)$key,
                (string)($template['label'] ?? ''),
                (string)($template['description'] ?? '')
            );

            $groups[$group_key]['items'][(string)$key] = $template;
        }

        return array_filter($groups, static fn($group) => !empty($group['items']));
    }

    private static function groupSavedTemplates(array $templates): array
    {
        $groups = [];
        foreach (self::templateCategoryLabels() as $key => $label) {
            $groups[$key] = [
                'label' => $label,
                'items' => [],
            ];
        }

        foreach ($templates as $key => $template) {
            $group_key = self::templateCategoryKey(
                (string)($template['category'] ?? ''),
                (string)$key,
                (string)($template['name'] ?? ''),
                (string)($template['description'] ?? '')
            );

            $groups[$group_key]['items'][(string)$key] = $template;
        }

        return array_filter($groups, static fn($group) => !empty($group['items']));
    }

    private static function templateCategoryKey(string $category, string $key = '', string $label = '', string $description = ''): string
    {
        $text = strtolower($category . ' ' . $key . ' ' . $label . ' ' . $description);

        $map = [
            'required'=> ['required'],
            'header'  => ['header', 'nav', 'navigation', 'menu'],
            'hero'    => ['hero', 'banner', 'above fold', 'above_the_fold'],
            'contact_details' => ['contact details', 'phone address', 'phone, address'],
            'contact_form' => ['contact form', 'iframe'],
            'map'     => ['map', 'location map', 'google maps'],
            'service_area' => ['service area', 'service_area', 'cities served', 'communities served'],
            'content_split' => ['content split', 'content_split', 'image left', 'text right'],
            'gallery' => ['gallery', 'project section', 'recent projects'],
            'blog'    => ['blog', 'posts grid', 'posts archive'],
            'content' => ['content', 'text', 'image', 'two-column', 'two column', 'body'],
            'cta'     => ['cta', 'call to action', 'conversion'],
            'faq'     => ['faq', 'accordion', 'question'],
            'reviews' => ['review', 'testimonial', 'rating'],
            'process' => ['process', 'steps', 'how it works'],
            'contact' => ['contact'],
            'footer'  => ['footer'],
            'custom'  => ['custom', 'saved'],
        ];

        foreach ($map as $group_key => $needles) {
            foreach ($needles as $needle) {
                if (strpos($text, $needle) !== false) {
                    return $group_key;
                }
            }
        }

        return 'other';
    }

    private static function renderCategoryFilters(string $context, array $groups): void
    {
        if (count($groups) < 2) {
            return;
        }

        echo '<div class="wnq-ai-category-filters" data-wnq-category-filter-context="' . esc_attr($context) . '">';
        echo '<button type="button" class="is-active" data-wnq-category-filter="all">All</button>';
        foreach ($groups as $key => $group) {
            echo '<button type="button" data-wnq-category-filter="' . esc_attr((string)$key) . '">' . esc_html((string)$group['label']) . '</button>';
        }
        echo '</div>';
    }

    private static function renderSectionTemplatePicker(array $groups, string $field_name, array $default_keys = []): void
    {
        echo '<div class="wnq-ai-section-catalog" data-wnq-template-catalog>';
        self::renderCategoryFilters($field_name, $groups);
        echo '<div class="wnq-ai-template-groups">';

        foreach ($groups as $category_key => $group) {
            $open = count($groups) === 1 || self::templateGroupHasDefaults((array)$group['items'], $default_keys);
            echo '<details class="wnq-ai-template-group" data-wnq-template-group="' . esc_attr((string)$category_key) . '"' . ($open ? ' open' : '') . '>';
            echo '<summary><span>' . esc_html((string)$group['label']) . '</span><em>' . esc_html((string)count($group['items'])) . '</em></summary>';
            echo '<div class="wnq-ai-elementor-section-list">';
            foreach ((array)$group['items'] as $key => $template) {
                $source = (string)($template['source'] ?? 'built_in');
                $theme = ucfirst((string)($template['theme'] ?? 'any'));
                $requirements = (string)($template['requirements_label'] ?? 'Elementor Pro');
                $required = (string)$key === ElementorSectionLibrary::TOP_BANNER;
                echo '<label class="wnq-ai-section-option">';
                echo '<input type="checkbox" name="' . esc_attr($field_name) . '[]" value="' . esc_attr((string)$key) . '" ' . checked($required || in_array((string)$key, $default_keys, true), true, false) . ($required ? ' disabled' : '') . '>';
                echo '<span>';
                echo '<strong>' . esc_html((string)($template['label'] ?? $key)) . '</strong>';
                echo '<small>' . esc_html(trim($theme . ' / ' . ($source === 'saved' ? 'Saved' : 'Built-in') . ' / ' . $requirements)) . '</small>';
                if (!empty($template['description'])) {
                    echo '<small>' . esc_html((string)$template['description']) . '</small>';
                }
                if (!empty($template['variables'])) {
                    echo '<span class="wnq-ai-mini-chips">';
                    foreach (array_slice((array)$template['variables'], 0, 6) as $variable) {
                        echo '<code>{{' . esc_html((string)$variable) . '}}</code>';
                    }
                    if (count((array)$template['variables']) > 6) {
                        echo '<code>+' . esc_html((string)(count((array)$template['variables']) - 6)) . '</code>';
                    }
                    echo '</span>';
                }
                echo '</span>';
                echo '</label>';
            }
            echo '</div>';
            echo '</details>';
        }

        echo '</div></div>';
    }

    private static function renderSavedTemplateGroups(array $groups): void
    {
        echo '<div class="wnq-ai-section-catalog" data-wnq-template-catalog>';
        self::renderCategoryFilters('saved-library', $groups);
        echo '<div class="wnq-ai-template-groups">';

        $index = 0;
        foreach ($groups as $category_key => $group) {
            echo '<details class="wnq-ai-template-group" data-wnq-template-group="' . esc_attr((string)$category_key) . '"' . ($index === 0 ? ' open' : '') . '>';
            echo '<summary><span>' . esc_html((string)$group['label']) . '</span><em>' . esc_html((string)count($group['items'])) . '</em></summary>';
            echo '<div class="wnq-ai-template-library-list">';
            foreach ((array)$group['items'] as $key => $template) {
                echo '<div class="wnq-ai-template-card">';
                echo '<div>';
                echo '<strong>' . esc_html((string)($template['name'] ?? $key)) . '</strong>';
                echo '<span>' . esc_html((string)($template['category'] ?? 'Custom')) . ' / ' . esc_html(ucfirst((string)($template['theme'] ?? 'any'))) . ' / Elementor Pro</span>';
                if (!empty($template['description'])) {
                    echo '<p>' . esc_html((string)$template['description']) . '</p>';
                }
                if (!empty($template['variables'])) {
                    echo '<div class="wnq-ai-variable-chips">';
                    foreach ((array)$template['variables'] as $variable) {
                        echo '<code>{{' . esc_html((string)$variable) . '}}</code>';
                    }
                    echo '</div>';
                } else {
                    echo '<div class="wnq-ai-variable-chips"><span class="wnq-ai-placeholder-warning">No AI placeholders</span></div>';
                }
                echo '</div>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Delete this saved template?\');">';
                wp_nonce_field('wnq_ai_elementor_delete_template_' . $key);
                echo '<input type="hidden" name="action" value="wnq_ai_elementor_delete_template">';
                echo '<input type="hidden" name="template_key" value="' . esc_attr((string)$key) . '">';
                echo '<button type="submit" class="button">Delete</button>';
                echo '</form>';
                echo '</div>';
            }
            echo '</div>';
            echo '</details>';
            $index++;
        }

        echo '</div></div>';
    }

    private static function templateGroupHasDefaults(array $items, array $default_keys): bool
    {
        foreach (array_keys($items) as $key) {
            if (in_array((string)$key, $default_keys, true)) {
                return true;
            }
        }

        return false;
    }

    private static function connectedAgents(): array
    {
        $client_profiles = [];
        foreach (Client::getAll() as $client) {
            $client_id = (string)($client['client_id'] ?? '');
            if ($client_id === '') {
                continue;
            }
            $services = $client['active_services'] ?? '';
            if (is_array($services)) {
                $services = implode(', ', array_map('sanitize_text_field', $services));
            } elseif (is_string($services) && $services !== '') {
                $decoded_services = json_decode($services, true);
                if (is_array($decoded_services)) {
                    $services = implode(', ', array_map('sanitize_text_field', $decoded_services));
                }
            }
            $client_profiles[$client_id] = [
                'label'    => $client['company'] ?: $client['name'] ?: $client_id,
                'phone'    => sanitize_text_field((string)($client['phone'] ?? '')),
                'website'  => esc_url_raw((string)($client['website'] ?? '')),
                'services' => sanitize_text_field((string)$services),
            ];
        }

        $agents = [];
        foreach (SEOHub::getAllAgentKeys() as $agent) {
            if (($agent['status'] ?? '') !== 'active') {
                continue;
            }
            $client_id = (string)($agent['client_id'] ?? '');
            $profile = $client_profiles[$client_id] ?? [];
            $agent['client_label'] = $profile['label'] ?? $client_id;
            $agent['client_phone'] = $profile['phone'] ?? '';
            $agent['client_website'] = $profile['website'] ?? '';
            $agent['client_services'] = $profile['services'] ?? '';
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
            'logo_image_url'            => 'Logo',
            'hero_background_image_url' => 'Hero Background',
            'content_image_url'         => 'Main Service Image',
            'gallery_image_1_url'       => 'Gallery Image 1',
            'gallery_image_2_url'       => 'Gallery Image 2',
            'gallery_image_3_url'       => 'Gallery Image 3',
            'gallery_image_4_url'       => 'Gallery Image 4',
            'gallery_image_5_url'       => 'Gallery Image 5',
            'gallery_image_6_url'       => 'Gallery Image 6',
            'split_image_url'           => 'Image + Text Section Image',
            'before_image_url'          => 'Before Image',
            'after_image_url'           => 'After Image',
            'featured_image_url'        => 'Featured Image',
            'hero_slide_1_url'          => 'Hero Slide 1',
            'hero_slide_2_url'          => 'Hero Slide 2',
            'hero_slide_3_url'          => 'Hero Slide 3',
            'hero_slide_4_url'          => 'Hero Slide 4',
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

    private static function payloadQualityIssues(array $payload, array $variables, array $image_variables): array
    {
        $issues = [];
        $image_variables = array_map([self::class, 'cleanPlaceholderKey'], $image_variables);
        $substantial_values = [];

        foreach ($variables as $variable) {
            $key = self::cleanPlaceholderKey((string)$variable);
            if ($key === '') {
                continue;
            }
            if (!array_key_exists($key, $payload)) {
                $issues[] = sprintf('Add the missing "%s" variable.', $key);
                continue;
            }

            $value = is_scalar($payload[$key]) ? trim(wp_strip_all_tags((string)$payload[$key])) : '';
            if (in_array($key, $image_variables, true) || self::isNonCopyVariable($key)) {
                continue;
            }

            $minimum = self::minimumWordsForVariable($key);
            if ($value === '') {
                if ($minimum > 0 && !preg_match('/(?:^|_)(?:review|testimonial)(?:_|$)/', $key)) {
                    $issues[] = sprintf('Write useful content for the empty "%s" variable.', $key);
                }
                continue;
            }

            $word_count = self::wordCount($value);
            if ($minimum > 0 && $word_count < $minimum) {
                $issues[] = sprintf('Expand "%s" to at least %d useful words; it currently has %d.', $key, $minimum, $word_count);
            }
            if (preg_match('/(?:^|_)(?:faq_)?question(?:_|$)/', $key) && substr($value, -1) !== '?') {
                $issues[] = sprintf('Rewrite "%s" as a clear question ending with a question mark.', $key);
            }
            if ($word_count >= 10 && !preg_match('/(?:^|_)(?:title|heading|headline|label|button|cta)(?:_|$)/', $key)) {
                $substantial_values[$key] = $value;
            }
        }

        $keys = array_keys($substantial_values);
        for ($i = 0, $count = count($keys); $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $similarity = self::contentSimilarity($substantial_values[$keys[$i]], $substantial_values[$keys[$j]]);
                if ($similarity >= 0.78) {
                    $issues[] = sprintf('Rewrite "%s" and "%s" so they provide distinct information instead of repeating each other.', $keys[$i], $keys[$j]);
                }
            }
        }

        return array_values(array_unique($issues));
    }

    private static function minimumWordsForVariable(string $key): int
    {
        if (preg_match('/(?:^|_)(?:title|heading|headline|label|button|cta|eyebrow|kicker)(?:_|$)/', $key)) {
            return 0;
        }
        if (preg_match('/(?:^|_)(?:faq_)?answer(?:_|$)/', $key)) {
            return 30;
        }
        if (preg_match('/(?:^|_)(?:paragraph|body|long_copy|content_copy)(?:_|$)/', $key)) {
            return 45;
        }
        if (preg_match('/(?:^|_)(?:description|subheadline|summary|intro|service_copy)(?:_|$)/', $key)) {
            return 25;
        }
        if (preg_match('/(?:^|_)(?:text|copy)(?:_|$)/', $key)) {
            return 25;
        }
        if (preg_match('/(?:^|_)(?:faq_)?question(?:_|$)/', $key)) {
            return 6;
        }

        return 0;
    }

    private static function isNonCopyVariable(string $key): bool
    {
        if (in_array($key, ['city', 'state', 'target_city', 'target_state'], true)) {
            return true;
        }

        return preg_match('/(?:^|_)(?:url|color|font|id|slug|alt|email|phone)(?:_|$)/', $key) === 1;
    }

    private static function wordCount(string $value): int
    {
        $words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}\']+/u', ' ', $value)));
        return count(array_filter((array)$words, static fn($word) => $word !== ''));
    }

    private static function contentSimilarity(string $left, string $right): float
    {
        $normalize = static function (string $value): array {
            $value = strtolower(wp_strip_all_tags($value));
            $words = preg_split('/\s+/', trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value)));
            $words = array_filter((array)$words, static fn($word) => strlen((string)$word) > 3);
            return array_values(array_unique($words));
        };

        $left_words = $normalize($left);
        $right_words = $normalize($right);
        if (!$left_words || !$right_words) {
            return 0.0;
        }

        $intersection = count(array_intersect($left_words, $right_words));
        $union = count(array_unique(array_merge($left_words, $right_words)));
        return $union > 0 ? $intersection / $union : 0.0;
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

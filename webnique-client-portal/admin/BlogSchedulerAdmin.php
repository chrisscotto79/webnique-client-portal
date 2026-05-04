<?php
/**
 * Blog Scheduler Admin Page
 *
 * Tabs:
 *   queue     — Title queue management (add/edit/delete posts)
 *   generate  — AI batch title generator
 *   settings  — Elementor template + "Always Link To" per-client list
 *
 * @package WebNique Portal
 */

namespace WNQ\Admin;

use WNQ\Models\BlogScheduler;
use WNQ\Models\SEOHub;
use WNQ\Models\Client;

if (!defined('ABSPATH')) {
    exit;
}

final class BlogSchedulerAdmin
{
    // ── Registration ────────────────────────────────────────────────────────

    public static function register(): void
    {
        add_action('wp_ajax_wnq_blog_generate_titles', [self::class, 'ajaxGenerateTitles']);
        add_action('wp_ajax_wnq_blog_publish_now',     [self::class, 'ajaxPublishNow']);
        add_action('wp_ajax_wnq_blog_mark_read',       [self::class, 'ajaxMarkNotifRead']);
        add_action('wp_ajax_wnq_blog_get_agents',      [self::class, 'ajaxGetAgents']);
    }

    // ── Page Router ─────────────────────────────────────────────────────────

    public static function renderPage(): void
    {
        self::checkCap();
        $tab = sanitize_key($_GET['tab'] ?? 'queue');

        self::renderHeader('Blog Scheduler');
        echo '<div class="wnq-blog-wrap">';
        self::renderTabs($tab);

        switch ($tab) {
            case 'generate':
                self::renderGenerateTab();
                break;
            case 'settings':
                self::renderSettingsTab();
                break;
            default:
                self::renderQueueTab();
        }

        echo '</div>'; // .wnq-blog-wrap
        self::renderFooter();
    }

    // ── Tabs ────────────────────────────────────────────────────────────────

    private static function renderTabs(string $current): void
    {
        $tabs = [
            'queue'    => '📋 Post Queue',
            'generate' => '✨ Title Generator',
            'settings' => '⚙️ Settings',
        ];
        echo '<div class="wnq-blog-tabs">';
        foreach ($tabs as $slug => $label) {
            $active = $current === $slug ? ' active' : '';
            $url    = admin_url('admin.php?page=wnq-seo-hub-blog&tab=' . $slug);
            echo '<a href="' . esc_url($url) . '" class="wnq-blog-tab' . $active . '">' . $label . '</a>';
        }
        echo '</div>';
    }

    // ── Queue Tab ───────────────────────────────────────────────────────────

    private static function renderQueueTab(): void
    {
        $clients    = Client::getByStatus('active');
        $client_id  = sanitize_text_field($_GET['client_id'] ?? ($clients[0]['client_id'] ?? ''));
        $posts      = $client_id ? BlogScheduler::getPostsByClient($client_id) : [];

        // Status notice
        $notice = sanitize_text_field($_GET['notice'] ?? '');
        if ($notice === 'added')    echo '<div class="wnq-notice success">✅ Post added to queue.</div>';
        if ($notice === 'deleted')  echo '<div class="wnq-notice success">✅ Post removed from queue.</div>';
        if ($notice === 'failed')   echo '<div class="wnq-notice error">❌ Publish failed. Check error message.</div>';
        if ($notice === 'published') echo '<div class="wnq-notice success">✅ Publishing triggered. Check the post queue for status.</div>';

        // Client selector
        echo '<div class="wnq-blog-client-bar">';
        echo '<form method="get" style="display:inline-flex;gap:8px;align-items:center;">';
        echo '<input type="hidden" name="page" value="wnq-seo-hub-blog">';
        echo '<input type="hidden" name="tab" value="queue">';
        echo '<select name="client_id" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;">';
        foreach ($clients as $c) {
            $selected = $c['client_id'] === $client_id ? ' selected' : '';
            echo '<option value="' . esc_attr($c['client_id']) . '"' . $selected . '>' . esc_html($c['company'] ?? $c['name'] ?? $c['client_id']) . '</option>';
        }
        echo '</select></form>';

        // Notification bell
        $unread = BlogScheduler::getUnreadCount();
        if ($unread > 0) {
            echo '<a href="#notifications" class="wnq-notif-bell">🔔 ' . $unread . ' new</a>';
        }
        echo '</div>';

        if (!$client_id) {
            echo '<p>No clients found. Add a client first.</p>';
            return;
        }

        // Add new post form
        $agents = BlogScheduler::getClientAgents($client_id);
        echo '<div class="wnq-blog-card">';
        echo '<h3>➕ Add Post to Queue</h3>';
        echo '<form method="post" action="' . admin_url('admin-post.php') . '" class="wnq-blog-add-form">';
        echo '<input type="hidden" name="action" value="wnq_blog_add_post">';
        echo '<input type="hidden" name="client_id" value="' . esc_attr($client_id) . '">';
        wp_nonce_field('wnq_blog_add_post');
        echo '<div class="wnq-blog-form-row">';
        echo '<input type="text" name="title" placeholder="Blog post title or working title..." required style="flex:2;">';
        echo '<select name="category_type" style="min-width:160px;">';
        foreach (['Informational' => 'Informational', 'Services' => 'Services', 'Seasonal' => 'Seasonal'] as $v => $l) {
            echo '<option value="' . $v . '">' . $l . '</option>';
        }
        echo '</select>';
        echo '<input type="text" name="focus_keyword" placeholder="Focus keyword (optional)" style="min-width:200px;">';
        echo '<input type="date" name="scheduled_date" style="min-width:150px;">';
        if (!empty($agents)) {
            echo '<select name="agent_key_id" style="min-width:160px;">';
            echo '<option value="">— Any Connected Site —</option>';
            foreach ($agents as $a) {
                $label = $a['site_name'] ?: parse_url($a['site_url'] ?? '', PHP_URL_HOST) ?: $a['site_url'];
                echo '<option value="' . (int)$a['id'] . '">' . esc_html($label) . '</option>';
            }
            echo '</select>';
        }
        echo '<button type="submit" class="button button-primary">Add</button>';
        echo '</div></form>';
        echo '</div>';

        // Post queue table
        echo '<div class="wnq-blog-card">';
        echo '<h3>📋 Scheduled Posts (' . count($posts) . ')</h3>';
        if (empty($posts)) {
            echo '<p style="color:#6b7280;">No posts in queue. Add titles above or use the Title Generator tab.</p>';
        } else {
            // Build agent lookup map for the site column
            $agent_map = [];
            foreach ($agents as $a) {
                $agent_map[(int)$a['id']] = $a['site_name'] ?: parse_url($a['site_url'] ?? '', PHP_URL_HOST) ?: $a['site_url'];
            }

            echo '<table class="widefat striped wnq-blog-table">';
            echo '<thead><tr><th>Title</th><th>Category</th><th>Keyword</th><th>Site</th><th>Scheduled</th><th>Status</th><th>Actions</th></tr></thead>';
            echo '<tbody>';
            foreach ($posts as $p) {
                $status_class = match($p['status']) {
                    'published'  => 'status-ok',
                    'failed'     => 'status-err',
                    'generating',
                    'publishing' => 'status-wait',
                    default      => 'status-pend',
                };
                echo '<tr>';
                echo '<td>';
                echo esc_html($p['generated_title'] ?: $p['title']);
                if (!empty($p['wp_post_url'])) {
                    echo ' <a href="' . esc_url($p['wp_post_url']) . '" target="_blank" style="font-size:11px;">[view]</a>';
                }
                if (!empty($p['error_message'])) {
                    echo '<br><small style="color:#dc2626;">' . esc_html(substr($p['error_message'], 0, 120)) . '</small>';
                }
                echo '</td>';
                echo '<td>' . esc_html($p['category_type']) . '</td>';
                echo '<td>' . esc_html($p['focus_keyword'] ?? '—') . '</td>';
                $site_label = !empty($p['agent_key_id']) ? ($agent_map[(int)$p['agent_key_id']] ?? '—') : '<span style="color:#9ca3af;">Any</span>';
                echo '<td style="font-size:12px;">' . esc_html(strip_tags($site_label)) . (!empty($p['agent_key_id']) ? '' : ' <span style="color:#9ca3af;">(any)</span>') . '</td>';
                echo '<td>' . esc_html($p['scheduled_date'] ?? '—') . '</td>';
                echo '<td><span class="wnq-status-badge ' . $status_class . '">' . esc_html($p['status']) . '</span></td>';
                echo '<td>';
                if ($p['status'] === 'pending') {
                    // Publish now button
                    echo '<button class="button button-small wnq-publish-now" data-id="' . (int)$p['id'] . '">▶ Publish Now</button> ';
                }
                if ($p['status'] === 'failed') {
                    // Try Again button
                    echo '<button class="button button-small wnq-publish-now" data-id="' . (int)$p['id'] . '" style="background:#fef2f2;border-color:#fca5a5;color:#991b1b;">↺ Try Again</button> ';
                }
                // Delete
                echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="display:inline;">';
                echo '<input type="hidden" name="action" value="wnq_blog_delete_post">';
                echo '<input type="hidden" name="post_id" value="' . (int)$p['id'] . '">';
                echo '<input type="hidden" name="client_id" value="' . esc_attr($client_id) . '">';
                wp_nonce_field('wnq_blog_delete_' . $p['id']);
                echo '<button type="submit" class="button button-small" onclick="return confirm(\'Delete this post?\')">🗑</button>';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';

        // Notifications panel
        $notifications = BlogScheduler::getNotifications(20);
        if (!empty($notifications)) {
            echo '<div class="wnq-blog-card" id="notifications">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;">';
            echo '<h3>🔔 Notifications</h3>';
            echo '<form method="post" action="' . admin_url('admin-post.php') . '" style="margin:0;">';
            echo '<input type="hidden" name="action" value="wnq_blog_mark_all_read">';
            wp_nonce_field('wnq_blog_mark_all_read');
            echo '<button type="submit" class="button button-small">Mark all read</button>';
            echo '</form>';
            echo '</div>';
        }

        // Publish Now JS — must live on the Queue tab where the button is rendered
        ?>
<script>
jQuery(function($) {
    $(document).on('click', '.wnq-publish-now', function() {
        if (!confirm('Publish this post now?\n\nThe AI will generate the full post and push it live to the client site. This may take 30-60 seconds.')) return;
        var id       = $(this).data('id');
        var $btn     = $(this);
        var origText = $btn.text();
        $btn.prop('disabled', true).text('⏳ Publishing...');

        $.post(WNQ_SEOHUB.ajaxUrl, {
            action:      'wnq_blog_publish_now',
            nonce:       WNQ_SEOHUB.nonce,
            schedule_id: id
        }, function(resp) {
            if (resp.success) {
                location.reload();
            } else {
                var msg = (resp.data && resp.data.message) ? resp.data.message : 'Unknown error';
                alert('❌ Publish failed:\n\n' + msg);
                $btn.prop('disabled', false).text(origText);
            }
        }).fail(function(xhr) {
            var msg = 'AJAX request failed.';
            try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
            alert('❌ ' + msg + '\n\nCheck your browser console for details.');
            $btn.prop('disabled', false).text(origText);
        });
    });
});
</script>
        <?php

        if (!empty($notifications)) {
            echo '<div class="wnq-blog-notifications">';
            foreach ($notifications as $n) {
                $unread_class = empty($n['is_read']) ? ' unread' : '';
                echo '<div class="wnq-blog-notif' . $unread_class . '">';
                echo '<strong>' . esc_html($n['title']) . '</strong>';
                if ($n['message']) echo '<p>' . esc_html($n['message']) . '</p>';
                if ($n['url'])     echo '<a href="' . esc_url($n['url']) . '" target="_blank">View post →</a>';
                echo '<span class="wnq-notif-time">' . esc_html($n['created_at']) . '</span>';
                echo '</div>';
            }
            echo '</div></div>';
        }
    }

    // ── Title Generator Tab ─────────────────────────────────────────────────

    private static function renderGenerateTab(): void
    {
        $clients   = Client::getByStatus('active');
        $client_id = sanitize_text_field($_GET['client_id'] ?? ($clients[0]['client_id'] ?? ''));

        echo '<div class="wnq-blog-card">';
        echo '<h3>✨ AI Batch Title Generator</h3>';
        echo '<p style="color:#6b7280;">Generate a batch of title ideas. Select the ones you want, set dates, then add them to the queue.</p>';

        echo '<div class="wnq-blog-generator">';

        // Client + options form
        $gen_agents = $client_id ? BlogScheduler::getClientAgents($client_id) : [];
        echo '<div class="wnq-blog-gen-options">';
        echo '<div class="wnq-blog-form-row" style="flex-wrap:wrap;">';
        echo '<select id="gen-client" style="min-width:200px;">';
        foreach ($clients as $c) {
            $selected = $c['client_id'] === $client_id ? ' selected' : '';
            echo '<option value="' . esc_attr($c['client_id']) . '"' . $selected . '>' . esc_html($c['company'] ?? $c['name'] ?? $c['client_id']) . '</option>';
        }
        echo '</select>';
        echo '<select id="gen-agent" style="min-width:160px;">';
        echo '<option value="">— Any Site —</option>';
        foreach ($gen_agents as $a) {
            $lbl = $a['site_name'] ?: parse_url($a['site_url'] ?? '', PHP_URL_HOST) ?: $a['site_url'];
            echo '<option value="' . (int)$a['id'] . '">' . esc_html($lbl) . '</option>';
        }
        echo '</select>';
        echo '<select id="gen-count" style="min-width:120px;">';
        foreach ([5, 10, 15] as $n) {
            echo '<option value="' . $n . '">' . $n . ' titles</option>';
        }
        echo '</select>';
        echo '<button type="button" id="wnq-gen-titles-btn" class="button button-primary">✨ Generate Titles</button>';
        echo '</div>';
        echo '<div id="wnq-gen-spinner" style="display:none;margin-top:8px;color:#6b7280;">Generating titles...</div>';
        echo '</div>';

        // Results area
        echo '<div id="wnq-gen-results" style="display:none;margin-top:16px;">';
        echo '<h4>Generated Titles — select ones you want to schedule:</h4>';
        echo '<div id="wnq-gen-list"></div>';
        echo '<div style="margin-top:12px;">';
        echo '<button type="button" id="wnq-add-selected-btn" class="button button-primary">➕ Add Selected to Queue</button>';
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .wnq-blog-generator
        echo '</div>'; // .wnq-blog-card

        // Inline JS for title generator
        ?>
<script>
jQuery(function($) {
    // Refresh agent dropdown when client changes
    $('#gen-client').on('change', function() {
        var clientId = $(this).val();
        var $agentSel = $('#gen-agent');
        $agentSel.html('<option value="">— Loading… —</option>').prop('disabled', true);
        $.post(WNQ_SEOHUB.ajaxUrl, {
            action:    'wnq_blog_get_agents',
            nonce:     WNQ_SEOHUB.nonce,
            client_id: clientId
        }, function(resp) {
            $agentSel.prop('disabled', false);
            if (resp.success) {
                var opts = '<option value="">— Any Site —</option>';
                (resp.data.agents || []).forEach(function(a) {
                    var lbl = a.site_name || a.site_url || a.id;
                    opts += '<option value="' + a.id + '">' + $('<div>').text(lbl).html() + '</option>';
                });
                $agentSel.html(opts);
            } else {
                $agentSel.html('<option value="">— Any Site —</option>');
            }
        }).fail(function() {
            $agentSel.prop('disabled', false).html('<option value="">— Any Site —</option>');
        });
    });

    $('#wnq-gen-titles-btn').on('click', function() {
        var clientId = $('#gen-client').val();
        var count    = $('#gen-count').val();
        if (!clientId) return alert('Please select a client.');

        $('#wnq-gen-spinner').show();
        $('#wnq-gen-results').hide();
        $(this).prop('disabled', true);

        $.post(WNQ_SEOHUB.ajaxUrl, {
            action:    'wnq_blog_generate_titles',
            nonce:     WNQ_SEOHUB.nonce,
            client_id: clientId,
            count:     count
        }, function(resp) {
            $('#wnq-gen-spinner').hide();
            $('#wnq-gen-titles-btn').prop('disabled', false);
            if (!resp.success) {
                alert('Error: ' + (resp.data?.message || 'Unknown error'));
                return;
            }
            var titles = resp.data.titles || [];
            var html = '';
            titles.forEach(function(t, i) {
                html += '<div class="wnq-gen-title-row">' +
                    '<input type="checkbox" id="gt' + i + '" class="wnq-gen-cb" ' +
                        'data-title="' + $('<div>').text(t.title).html() + '" ' +
                        'data-category="' + $('<div>').text(t.category).html() + '" ' +
                        'data-keyword="' + $('<div>').text(t.keyword).html() + '">' +
                    '<label for="gt' + i + '">' +
                        '<strong>' + $('<div>').text(t.title).html() + '</strong> ' +
                        '<span class="wnq-gen-tag">' + $('<div>').text(t.category).html() + '</span>' +
                        (t.keyword ? ' <span class="wnq-gen-kw">🔑 ' + $('<div>').text(t.keyword).html() + '</span>' : '') +
                    '</label>' +
                    '<input type="date" class="wnq-gen-date" placeholder="Schedule date">' +
                    '</div>';
            });
            $('#wnq-gen-list').html(html || '<p>No titles generated. Check AI settings.</p>');
            if (titles.length) $('#wnq-gen-results').show();
        }).fail(function() {
            $('#wnq-gen-spinner').hide();
            $('#wnq-gen-titles-btn').prop('disabled', false);
            alert('Request failed. Check AI settings.');
        });
    });

    $('#wnq-add-selected-btn').on('click', function() {
        var selected = [];
        $('.wnq-gen-cb:checked').each(function() {
            var $row = $(this).closest('.wnq-gen-title-row');
            selected.push({
                title:    $(this).data('title'),
                category: $(this).data('category'),
                keyword:  $(this).data('keyword'),
                date:     $row.find('.wnq-gen-date').val()
            });
        });
        if (!selected.length) return alert('Select at least one title.');

        $.post(WNQ_SEOHUB.ajaxUrl, {
            action:       'wnq_blog_add_batch',
            nonce:        WNQ_SEOHUB.nonce,
            client_id:    $('#gen-client').val(),
            agent_key_id: $('#gen-agent').val(),
            posts:        JSON.stringify(selected)
        }, function(resp) {
            if (resp.success) {
                alert('✅ ' + resp.data.added + ' post(s) added to queue!');
                window.location = '<?php echo admin_url('admin.php?page=wnq-seo-hub-blog&tab=queue'); ?>&client_id=' + $('#gen-client').val();
            } else {
                alert('Error: ' + (resp.data?.message || 'Unknown'));
            }
        });
    });

    // (Publish Now handler is registered on the Queue tab)
});
</script>
        <?php
    }

    // ── Settings Tab ────────────────────────────────────────────────────────

    private static function renderSettingsTab(): void
    {
        $clients    = Client::getByStatus('active');
        $client_id  = sanitize_text_field($_GET['client_id'] ?? ($clients[0]['client_id'] ?? ''));
        $always_links = $client_id ? BlogScheduler::getAlwaysLinkTo($client_id) : [];
        $template_json = get_option('wnq_blog_elementor_template', '');

        $saved = sanitize_text_field($_GET['settings_saved'] ?? '');
        if ($saved === '1') echo '<div class="wnq-notice success">✅ Settings saved.</div>';

        // Client selector for template section
        echo '<div class="wnq-blog-card" style="padding:14px 20px;">';
        echo '<div style="display:flex;align-items:center;gap:12px;">';
        echo '<strong style="white-space:nowrap;">Client Site:</strong>';
        echo '<form method="get" style="margin:0;">';
        echo '<input type="hidden" name="page" value="wnq-seo-hub-blog">';
        echo '<input type="hidden" name="tab" value="settings">';
        echo '<select name="client_id" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;min-width:220px;">';
        foreach ($clients as $c) {
            $sel = $c['client_id'] === $client_id ? ' selected' : '';
            echo '<option value="' . esc_attr($c['client_id']) . '"' . $sel . '>' . esc_html($c['company'] ?? $c['name'] ?? $c['client_id']) . '</option>';
        }
        echo '</select>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        // Per-site Elementor templates
        $settings_agents = BlogScheduler::getClientAgents($client_id);
        $widget_hint = '<p style="color:#6b7280;font-size:12px;"><strong>Widget IDs:</strong> Heading <code>5af58bd2</code> (H1) · Text Editor <code>5b794435</code> (body) · Text Editor <code>4861ee91</code> (TOC) · Image <code>1b605b78</code> (featured — add manually)</p>';

        if (!empty($settings_agents)) {
            foreach ($settings_agents as $a) {
                $site_label   = $a['site_name'] ?: parse_url($a['site_url'] ?? '', PHP_URL_HOST) ?: $a['site_url'];
                $site_tpl     = get_option('wnq_blog_template_site_' . (int)$a['id'], '');
                echo '<div class="wnq-blog-card">';
                echo '<h3>🎨 Elementor Template — <span style="color:#2563eb;">' . esc_html($site_label) . '</span></h3>';
                echo '<p style="color:#6b7280;">Template for <strong>' . esc_html($a['site_url'] ?? '') . '</strong>. If empty the global fallback template is used.</p>';
                echo $widget_hint;
                echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
                echo '<input type="hidden" name="action" value="wnq_blog_save_template">';
                echo '<input type="hidden" name="agent_key_id" value="' . (int)$a['id'] . '">';
                echo '<input type="hidden" name="client_id" value="' . esc_attr($client_id) . '">';
                wp_nonce_field('wnq_blog_save_template');
                echo '<textarea name="elementor_template" rows="8" style="width:100%;font-family:monospace;font-size:12px;border:1px solid #d1d5db;border-radius:6px;padding:8px;">' . esc_textarea($site_tpl) . '</textarea>';
                echo '<p style="margin-top:8px;"><button type="submit" class="button button-primary">💾 Save Template for ' . esc_html($site_label) . '</button></p>';
                echo '</form>';
                echo '</div>';
            }
        }

        // Global fallback template
        echo '<div class="wnq-blog-card">';
        echo '<h3>🎨 Global Fallback Template</h3>';
        echo '<p style="color:#6b7280;">Used when no per-site template is saved. Paste your default Elementor blog layout JSON here.</p>';
        echo $widget_hint;
        echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
        echo '<input type="hidden" name="action" value="wnq_blog_save_template">';
        echo '<input type="hidden" name="agent_key_id" value="0">';
        echo '<input type="hidden" name="client_id" value="' . esc_attr($client_id) . '">';
        wp_nonce_field('wnq_blog_save_template');
        echo '<textarea name="elementor_template" rows="10" style="width:100%;font-family:monospace;font-size:12px;border:1px solid #d1d5db;border-radius:6px;padding:8px;">' . esc_textarea($template_json) . '</textarea>';
        echo '<p style="margin-top:8px;"><button type="submit" class="button button-primary">💾 Save Global Fallback Template</button></p>';
        echo '</form>';
        echo '</div>';

        // Always Link To
        echo '<div class="wnq-blog-card">';
        echo '<h3>🔗 Always Link To — ' . esc_html($client_id) . '</h3>';
        echo '<p style="color:#6b7280;">These links take priority in every blog post for this client. Add pages you always want to reference (service pages, key landing pages).</p>';

        echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
        echo '<input type="hidden" name="action" value="wnq_blog_save_always_link">';
        echo '<input type="hidden" name="client_id" value="' . esc_attr($client_id) . '">';
        wp_nonce_field('wnq_blog_save_always_link');

        echo '<table class="widefat" id="wnq-always-link-table" style="margin-bottom:12px;">';
        echo '<thead><tr><th>URL</th><th>Anchor Text</th><th>Context Keywords (comma-sep)</th><th></th></tr></thead>';
        echo '<tbody>';
        foreach ($always_links as $i => $lnk) {
            echo '<tr>';
            echo '<td><input type="url" name="always_link[' . $i . '][url]" value="' . esc_url($lnk['url'] ?? '') . '" style="width:100%;" placeholder="https://..."></td>';
            echo '<td><input type="text" name="always_link[' . $i . '][anchor]" value="' . esc_attr($lnk['anchor'] ?? '') . '" style="width:100%;"></td>';
            echo '<td><input type="text" name="always_link[' . $i . '][keywords]" value="' . esc_attr($lnk['keywords'] ?? '') . '" style="width:100%;" placeholder="roofing, roof repair"></td>';
            echo '<td><button type="button" class="button button-small wnq-remove-link">✕</button></td>';
            echo '</tr>';
        }
        echo '</tbody>';
        echo '</table>';
        echo '<button type="button" class="button" id="wnq-add-link-row">+ Add Row</button> ';
        echo '<button type="submit" class="button button-primary" style="margin-left:8px;">💾 Save Links</button>';
        echo '</form>';
        echo '</div>';

        // Inline JS for table management
        ?>
<script>
jQuery(function($) {
    var rowIdx = <?php echo count($always_links); ?>;
    $('#wnq-add-link-row').on('click', function() {
        var i = rowIdx++;
        $('#wnq-always-link-table tbody').append(
            '<tr>' +
            '<td><input type="url" name="always_link['+i+'][url]" style="width:100%;" placeholder="https://..."></td>' +
            '<td><input type="text" name="always_link['+i+'][anchor]" style="width:100%;"></td>' +
            '<td><input type="text" name="always_link['+i+'][keywords]" style="width:100%;" placeholder="roofing, roof repair"></td>' +
            '<td><button type="button" class="button button-small wnq-remove-link">✕</button></td>' +
            '</tr>'
        );
    });
    $(document).on('click', '.wnq-remove-link', function() {
        $(this).closest('tr').remove();
    });
});
</script>
        <?php
    }

    // ── AJAX Handlers ───────────────────────────────────────────────────────

    public static function ajaxGenerateTitles(): void
    {
        check_ajax_referer('wnq_seohub_nonce', 'nonce');
        self::checkCap();

        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $count     = max(5, min(15, (int)($_POST['count'] ?? 10)));

        if (!$client_id) {
            wp_send_json_error(['message' => 'Missing client_id']);
        }

        $profile  = SEOHub::getProfile($client_id);
        $client   = Client::getByClientId($client_id) ?? [];
        $existing = self::getExistingTitles($client_id);

        $biz      = $client['company'] ?? $client['name'] ?? $client_id;
        $services = implode(', ', (array)($profile['primary_services'] ?? []));
        $location = implode(', ', (array)($profile['service_locations'] ?? []));

        $result = \WNQ\Services\AIEngine::generate('blog_titles_batch', [
            'business_name'  => $biz,
            'services'       => $services,
            'location'       => $location,
            'count'          => $count,
            'existing_titles'=> $existing,
        ], $client_id, [
            'no_cache'    => true,
            'temperature' => 0.9,
        ]);

        if (!$result['success']) {
            wp_send_json_error(['message' => $result['error'] ?? 'AI generation failed']);
        }

        $titles = self::parseTitlesBatch($result['content']);
        wp_send_json_success(['titles' => $titles]);
    }

    public static function ajaxPublishNow(): void
    {
        check_ajax_referer('wnq_seohub_nonce', 'nonce');
        self::checkCap();

        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        if (!$schedule_id) {
            wp_send_json_error(['message' => 'Invalid schedule_id']);
        }

        $result = \WNQ\Services\BlogPublisher::processPost($schedule_id);
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    public static function ajaxMarkNotifRead(): void
    {
        check_ajax_referer('wnq_seohub_nonce', 'nonce');
        self::checkCap();
        $id = (int)($_POST['id'] ?? 0);
        if ($id) BlogScheduler::markRead($id);
        wp_send_json_success();
    }

    public static function ajaxGetAgents(): void
    {
        check_ajax_referer('wnq_seohub_nonce', 'nonce');
        self::checkCap();
        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $agents = $client_id ? BlogScheduler::getClientAgents($client_id) : [];
        wp_send_json_success(['agents' => $agents]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private static function getExistingTitles(string $client_id): string
    {
        $posts = BlogScheduler::getPostsByClient($client_id, 30);
        if (empty($posts)) return 'None yet.';
        $titles = array_map(fn($p) => $p['generated_title'] ?: $p['title'], $posts);
        return implode("\n", $titles);
    }

    /**
     * Parse the blog_titles_batch AI response.
     * Expected format: "1. Title | Category | Focus Keyword\n2. ..."
     */
    private static function parseTitlesBatch(string $raw): array
    {
        $lines   = explode("\n", $raw);
        $results = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;
            // Strip leading number + period
            $line = preg_replace('/^\d+\.\s*/', '', $line);
            $parts = array_map('trim', explode('|', $line));
            if (count($parts) >= 1 && strlen($parts[0]) > 5) {
                $results[] = [
                    'title'    => $parts[0],
                    'category' => $parts[1] ?? 'Informational',
                    'keyword'  => $parts[2] ?? '',
                ];
            }
        }
        return $results;
    }

    private static function checkCap(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('Access denied.');
        }
    }

    // ── Header/Footer (reuse existing pattern) ──────────────────────────────

    private static function renderHeader(string $title): void
    {
        echo '<div class="wrap wnq-hub-wrap">';
        echo '<div class="wnq-hub-masthead">';
        echo '<div class="wnq-hub-logo">🔭 WebNique<span>SEO OS</span></div>';
        echo '<nav class="wnq-hub-nav">';
        $nav_items = [
            'wnq-seo-hub'          => 'Dashboard',
            'wnq-seo-hub-clients'  => 'Clients',
            'wnq-seo-hub-keywords' => 'Keywords',
            'wnq-seo-hub-content'  => 'Content',
            'wnq-seo-hub-audits'   => 'Audits',
            'wnq-seo-hub-reports'  => 'Reports',
            'wnq-seo-hub-blog'     => 'Blog Scheduler',
            'wnq-seo-hub-api'      => 'API',
            'wnq-seo-hub-settings' => 'Settings',
        ];
        $current = $_GET['page'] ?? 'wnq-seo-hub';
        foreach ($nav_items as $slug => $label) {
            $active = $current === $slug ? 'active' : '';
            echo '<a href="' . admin_url('admin.php?page=' . $slug) . '" class="' . $active . '">' . $label . '</a>';
        }
        echo '</nav></div>';
        echo '<h1 class="wnq-hub-page-title">' . esc_html($title) . '</h1>';

        // Minimal inline styles for blog scheduler UI
        echo '<style>
        .wnq-blog-wrap { max-width: 1100px; }
        .wnq-blog-tabs { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #e5e7eb; padding-bottom: 0; }
        .wnq-blog-tab { padding: 8px 16px; text-decoration: none; color: #374151; border-radius: 6px 6px 0 0; font-weight: 500; border-bottom: 2px solid transparent; margin-bottom: -2px; }
        .wnq-blog-tab.active { color: #2563eb; border-bottom-color: #2563eb; background: #eff6ff; }
        .wnq-blog-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; margin-bottom: 16px; }
        .wnq-blog-card h3 { margin: 0 0 12px; font-size: 15px; }
        .wnq-blog-form-row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .wnq-blog-form-row input, .wnq-blog-form-row select { padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px; }
        .wnq-blog-table th, .wnq-blog-table td { padding: 8px 10px; vertical-align: top; }
        .wnq-status-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .status-ok   { background: #dcfce7; color: #166534; }
        .status-err  { background: #fef2f2; color: #991b1b; }
        .status-wait { background: #fef9c3; color: #854d0e; }
        .status-pend { background: #f3f4f6; color: #374151; }
        .wnq-blog-client-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; }
        .wnq-notif-bell { background: #fee2e2; color: #991b1b; border-radius: 12px; padding: 4px 12px; text-decoration: none; font-weight: 600; font-size: 13px; }
        .wnq-blog-notifications { display: flex; flex-direction: column; gap: 8px; }
        .wnq-blog-notif { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 14px; position: relative; }
        .wnq-blog-notif.unread { background: #eff6ff; border-color: #bfdbfe; }
        .wnq-blog-notif .wnq-notif-time { font-size: 11px; color: #9ca3af; display: block; margin-top: 4px; }
        .wnq-notice { padding: 10px 14px; border-radius: 6px; margin-bottom: 12px; }
        .wnq-notice.success { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
        .wnq-notice.error   { background: #fef2f2; color: #991b1b; border: 1px solid #fca5a5; }
        .wnq-gen-title-row { display: flex; align-items: center; gap: 8px; padding: 8px; border: 1px solid #e5e7eb; border-radius: 6px; margin-bottom: 6px; }
        .wnq-gen-title-row label { flex: 1; cursor: pointer; }
        .wnq-gen-title-row input[type=date] { min-width: 140px; padding: 4px 8px; border: 1px solid #d1d5db; border-radius: 4px; }
        .wnq-gen-tag { background: #ede9fe; color: #5b21b6; border-radius: 10px; padding: 1px 8px; font-size: 11px; margin-left: 6px; }
        .wnq-gen-kw { color: #6b7280; font-size: 12px; }
        </style>';
    }

    private static function renderFooter(): void
    {
        echo '</div>'; // .wnq-hub-wrap
    }
}

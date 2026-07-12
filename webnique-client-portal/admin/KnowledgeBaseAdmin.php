<?php
/**
 * WordPress admin UI for the Golden Telegram knowledge base.
 *
 * @package Golden Web Marketing Portal
 */

namespace WNQ\Admin;

use WNQ\Models\KnowledgeBase;

if (!defined('ABSPATH')) {
    exit;
}

final class KnowledgeBaseAdmin
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'addSubmenu'], 16);
        add_action('admin_post_wnq_save_knowledge_item', [self::class, 'handleSave']);
        add_action('admin_post_wnq_delete_knowledge_item', [self::class, 'handleDelete']);
    }

    public static function addSubmenu(): void
    {
        $capability = current_user_can('wnq_manage_portal') ? 'wnq_manage_portal' : 'manage_options';
        add_submenu_page(
            'wnq-portal',
            'AI Knowledge Base',
            'AI Knowledge Base',
            $capability,
            'wnq-ai-knowledge',
            [self::class, 'render']
        );
    }

    public static function render(): void
    {
        self::requirePermission();
        KnowledgeBase::ensureSchema();
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $all_items = KnowledgeBase::getAll();
        $items = $search === '' ? $all_items : KnowledgeBase::getAll($search);
        $edit_id = absint($_GET['edit'] ?? 0);
        $editing = $edit_id > 0 ? KnowledgeBase::getById($edit_id) : null;
        $recent = KnowledgeBase::recentQueries(12);
        $notice = get_transient('wnq_knowledge_notice_' . get_current_user_id());
        if (is_array($notice)) {
            delete_transient('wnq_knowledge_notice_' . get_current_user_id());
        }
        $active_count = count(array_filter($all_items, static fn(array $item): bool => ($item['status'] ?? '') === 'active'));
        ?>
        <div class="wrap wnq-kb-page">
            <div class="wnq-kb-header">
                <div>
                    <span>GOLDEN AI</span>
                    <h1>AI Knowledge Base</h1>
                    <p>Give Golden verified SOPs and internal information without exposing it to client portal users.</p>
                </div>
                <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=wnq-portal')); ?>">Telegram Settings</a>
            </div>

            <?php if (is_array($notice)): ?>
                <div class="notice <?php echo !empty($notice['ok']) ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html((string)($notice['message'] ?? 'Knowledge Base updated.')); ?></p></div>
            <?php endif; ?>

            <div class="wnq-kb-stats">
                <div><strong><?php echo (int)count($all_items); ?></strong><span>Knowledge items</span></div>
                <div><strong><?php echo (int)$active_count; ?></strong><span>Available to Golden</span></div>
                <div><strong><?php echo (int)count($recent); ?></strong><span>Recent AI questions</span></div>
            </div>

            <div class="wnq-kb-guide">
                <div><strong>Ask naturally</strong><span>“Hey Golden: when is Lucas payment date?”</span></div>
                <div><strong>Ask with a command</strong><span><code>/ask What is our new-client SOP?</code></span></div>
                <div><strong>Telegram privacy</strong><span>Disable group privacy for the bot in BotFather to receive “Hey Golden” messages. <code>/ask</code> works with privacy enabled.</span></div>
            </div>

            <div class="wnq-kb-live-source">
                <div><span>LIVE WORDPRESS SOURCE</span><strong>Money Management is connected</strong><p>Golden can read active monthly client revenue, configured processing fees, recorded income, expenses, net totals, recent entries, and six-month trends. Live totals stay synchronized automatically and remain read-only.</p></div>
                <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=wnq-clients&tab=finance')); ?>">Open Money Management</a>
            </div>

            <div class="wnq-kb-layout">
                <section class="wnq-kb-card">
                    <div class="wnq-kb-card-head">
                        <div><span>KNOWLEDGE EDITOR</span><h2><?php echo $editing ? 'Edit Information' : 'Add Information'; ?></h2></div>
                        <?php if ($editing): ?><a href="<?php echo esc_url(admin_url('admin.php?page=wnq-ai-knowledge')); ?>">Cancel edit</a><?php endif; ?>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" class="wnq-kb-form">
                        <?php wp_nonce_field('wnq_save_knowledge_item', 'wnq_nonce'); ?>
                        <input type="hidden" name="action" value="wnq_save_knowledge_item">
                        <input type="hidden" name="id" value="<?php echo (int)($editing['id'] ?? 0); ?>">
                        <label><span>Title</span><input type="text" name="title" required maxlength="255" value="<?php echo esc_attr((string)($editing['title'] ?? '')); ?>" placeholder="Golden Package New Client SOP"></label>
                        <div class="wnq-kb-row">
                            <label><span>Category</span><select name="category">
                                <?php foreach (KnowledgeBase::categories() as $key => $label): ?>
                                    <option value="<?php echo esc_attr($key); ?>" <?php selected((string)($editing['category'] ?? 'general'), $key); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select></label>
                            <label><span>Status</span><select name="status"><option value="active" <?php selected((string)($editing['status'] ?? 'active'), 'active'); ?>>Active</option><option value="draft" <?php selected((string)($editing['status'] ?? ''), 'draft'); ?>>Draft</option></select></label>
                        </div>
                        <label><span>Information</span><textarea name="content" rows="15" placeholder="Paste the complete SOP, policy, checklist, or internal reference here."><?php echo esc_textarea((string)($editing['content'] ?? '')); ?></textarea></label>
                        <label class="wnq-kb-upload"><span>Upload information</span><input type="file" name="knowledge_file" accept=".txt,.md,.csv,.json,.html,.htm,.docx"><small>TXT, MD, CSV, JSON, HTML, or DOCX. Maximum 5 MB. Extracted text is stored in WordPress; the original file is discarded.</small><?php if (!empty($editing['source_name'])): ?><small>Current source: <strong><?php echo esc_html((string)$editing['source_name']); ?></strong></small><?php endif; ?></label>
                        <button type="submit" class="button button-primary button-large"><?php echo $editing ? 'Update Knowledge' : 'Add to Knowledge Base'; ?></button>
                    </form>
                </section>

                <section class="wnq-kb-card">
                    <div class="wnq-kb-card-head"><div><span>LIBRARY</span><h2>Stored Information</h2></div></div>
                    <form method="get" class="wnq-kb-search"><input type="hidden" name="page" value="wnq-ai-knowledge"><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Search SOPs and information"><button class="button">Search</button></form>
                    <div class="wnq-kb-list">
                        <?php if ($items === []): ?><p class="wnq-kb-empty">No knowledge has been added yet.</p><?php endif; ?>
                        <?php foreach ($items as $item): ?>
                            <article>
                                <div><span class="wnq-kb-status is-<?php echo esc_attr((string)$item['status']); ?>"><?php echo esc_html(ucfirst((string)$item['status'])); ?></span><small><?php echo esc_html(KnowledgeBase::categories()[$item['category']] ?? 'General'); ?></small></div>
                                <h3><?php echo esc_html((string)$item['title']); ?></h3>
                                <p><?php echo esc_html(wp_trim_words((string)$item['content'], 28)); ?></p>
                                <footer>
                                    <span>Updated <?php echo esc_html(mysql2date('M j, Y', (string)$item['updated_at'])); ?></span>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=wnq-ai-knowledge&edit=' . (int)$item['id'])); ?>">Edit</a>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Delete this knowledge item?');">
                                        <?php wp_nonce_field('wnq_delete_knowledge_item_' . (int)$item['id'], 'wnq_nonce'); ?>
                                        <input type="hidden" name="action" value="wnq_delete_knowledge_item"><input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>"><button type="submit" class="button-link-delete">Delete</button>
                                    </form>
                                </footer>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <section class="wnq-kb-card wnq-kb-audit">
                <div class="wnq-kb-card-head"><div><span>READ-ONLY AUDIT</span><h2>Recent Golden Questions</h2></div><small>Answers are logged for quality review. No client record is changed.</small></div>
                <div class="wnq-kb-audit-list">
                    <?php if ($recent === []): ?><p class="wnq-kb-empty">Golden has not answered a question yet.</p><?php endif; ?>
                    <?php foreach ($recent as $log): $sources = json_decode((string)($log['sources'] ?? '[]'), true); ?>
                        <article><div><strong><?php echo esc_html((string)$log['question']); ?></strong><span class="wnq-kb-status is-<?php echo esc_attr((string)$log['status']); ?>"><?php echo esc_html(ucwords(str_replace('_', ' ', (string)$log['status']))); ?></span></div><p><?php echo esc_html(wp_trim_words((string)$log['answer'], 45)); ?></p><small><?php echo esc_html(mysql2date('M j, Y g:i a', (string)$log['created_at'])); ?><?php echo is_array($sources) && $sources !== [] ? ' · ' . esc_html(implode(', ', array_slice($sources, 0, 4))) : ''; ?></small></article>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
        <style>
            .wnq-kb-page{max-width:1500px}.wnq-kb-header{display:flex;align-items:flex-start;justify-content:space-between;gap:24px;margin:24px 0}.wnq-kb-header span,.wnq-kb-card-head span,.wnq-kb-live-source span{color:#8a6b00;font-size:12px;font-weight:800;letter-spacing:.08em}.wnq-kb-header h1{font-size:34px;margin:6px 0}.wnq-kb-header p{font-size:16px;color:#5f6368;margin:0}.wnq-kb-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:18px}.wnq-kb-stats div,.wnq-kb-guide,.wnq-kb-live-source,.wnq-kb-card{background:#fff;border:1px solid #ded9ca;border-radius:8px;box-shadow:0 8px 24px rgba(38,34,22,.06)}.wnq-kb-stats div{padding:20px}.wnq-kb-stats strong{display:block;font-size:28px}.wnq-kb-stats span{color:#646970}.wnq-kb-guide{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1px;overflow:hidden;margin-bottom:18px}.wnq-kb-guide div{padding:18px;background:#fffcf1}.wnq-kb-guide strong,.wnq-kb-guide span{display:block}.wnq-kb-guide span{margin-top:6px;color:#5f6368;line-height:1.5}.wnq-kb-live-source{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:18px 20px;margin-bottom:18px;border-left:4px solid #c7a82d;background:#fffcf1}.wnq-kb-live-source strong{display:block;margin:5px 0;font-size:17px}.wnq-kb-live-source p{margin:0;color:#5f6368;line-height:1.5}.wnq-kb-live-source .button{flex:0 0 auto}.wnq-kb-layout{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(420px,.9fr);gap:18px}.wnq-kb-card{padding:24px}.wnq-kb-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:20px}.wnq-kb-card-head h2{font-size:23px;margin:5px 0 0}.wnq-kb-form label,.wnq-kb-form label span{display:block}.wnq-kb-form label{margin-bottom:16px}.wnq-kb-form label span{font-weight:700;margin-bottom:7px}.wnq-kb-form input[type=text],.wnq-kb-form select,.wnq-kb-form textarea{width:100%;max-width:none}.wnq-kb-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}.wnq-kb-upload{padding:16px;border:1px dashed #c7a82d;border-radius:7px;background:#fffcf1}.wnq-kb-upload small{display:block;margin-top:8px;color:#646970}.wnq-kb-search{display:flex;gap:8px;margin-bottom:16px}.wnq-kb-search input{flex:1}.wnq-kb-list{display:grid;gap:12px;max-height:760px;overflow:auto;padding-right:4px}.wnq-kb-list article,.wnq-kb-audit-list article{border:1px solid #e3dfd3;border-radius:7px;padding:16px}.wnq-kb-list article>div,.wnq-kb-audit-list article>div{display:flex;align-items:center;justify-content:space-between;gap:10px}.wnq-kb-list h3{margin:10px 0 5px}.wnq-kb-list p,.wnq-kb-audit-list p{color:#50545a;line-height:1.5}.wnq-kb-list footer{display:flex;align-items:center;gap:14px;border-top:1px solid #eee9dc;padding-top:10px}.wnq-kb-list footer span{margin-right:auto;color:#71757a}.wnq-kb-list footer form{margin:0}.wnq-kb-status{display:inline-flex;padding:4px 8px;border-radius:999px;background:#eef0f2;color:#3c434a;font-size:11px;font-weight:800}.wnq-kb-status.is-active,.wnq-kb-status.is-answered,.wnq-kb-status.is-answered_direct,.wnq-kb-status.is-greeting{background:#dcf4e4;color:#17672e}.wnq-kb-status.is-draft,.wnq-kb-status.is-needs_clarification,.wnq-kb-status.is-rate_limited,.wnq-kb-status.is-no_source{background:#fff1c7;color:#785900}.wnq-kb-status.is-ai_error,.wnq-kb-status.is-ai_unavailable{background:#fce1df;color:#912018}.wnq-kb-audit{margin-top:18px}.wnq-kb-audit-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.wnq-kb-audit-list small{color:#71757a}.wnq-kb-empty{padding:28px;text-align:center;background:#f7f6f2;color:#646970;border-radius:7px}@media(max-width:900px){.wnq-kb-stats,.wnq-kb-guide,.wnq-kb-layout,.wnq-kb-audit-list{grid-template-columns:1fr}.wnq-kb-live-source{align-items:flex-start;flex-direction:column}.wnq-kb-row{grid-template-columns:1fr}}
        </style>
        <?php
    }

    public static function handleSave(): void
    {
        self::requirePermission();
        check_admin_referer('wnq_save_knowledge_item', 'wnq_nonce');
        $id = absint($_POST['id'] ?? 0);
        $existing = $id > 0 ? KnowledgeBase::getById($id) : null;
        $content = sanitize_textarea_field(wp_unslash($_POST['content'] ?? ''));
        $source_name = sanitize_file_name((string)($existing['source_name'] ?? ''));
        if (!empty($_FILES['knowledge_file'])) {
            $upload = KnowledgeBase::extractUpload($_FILES['knowledge_file']);
            if (empty($upload['ok'])) {
                self::redirectWithNotice(false, (string)($upload['error'] ?? 'The knowledge file could not be imported.'), $id);
            }
            if (!empty($upload['content'])) {
                $content = trim($content . ($content !== '' ? "\n\n" : '') . (string)$upload['content']);
                $source_name = (string)($upload['name'] ?? '');
            }
        }
        $saved = KnowledgeBase::save([
            'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'category' => sanitize_key(wp_unslash($_POST['category'] ?? 'general')),
            'status' => sanitize_key(wp_unslash($_POST['status'] ?? 'active')),
            'content' => $content,
            'source_name' => $source_name,
        ], $id);
        self::redirectWithNotice((bool)$saved, $saved ? 'Knowledge Base updated.' : 'A title and readable information are required.', $saved ? 0 : $id);
    }

    public static function handleDelete(): void
    {
        self::requirePermission();
        $id = absint($_POST['id'] ?? 0);
        check_admin_referer('wnq_delete_knowledge_item_' . $id, 'wnq_nonce');
        $deleted = $id > 0 && KnowledgeBase::delete($id);
        self::redirectWithNotice($deleted, $deleted ? 'Knowledge item deleted.' : 'The knowledge item could not be deleted.');
    }

    private static function redirectWithNotice(bool $ok, string $message, int $edit_id = 0): void
    {
        set_transient('wnq_knowledge_notice_' . get_current_user_id(), ['ok' => $ok, 'message' => sanitize_text_field($message)], 5 * MINUTE_IN_SECONDS);
        $url = admin_url('admin.php?page=wnq-ai-knowledge');
        if ($edit_id > 0) {
            $url = add_query_arg('edit', $edit_id, $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    private static function requirePermission(): void
    {
        if (!current_user_can('wnq_manage_portal') && !current_user_can('manage_options')) {
            wp_die('You do not have permission to manage the AI Knowledge Base.', 'Forbidden', ['response' => 403]);
        }
    }
}

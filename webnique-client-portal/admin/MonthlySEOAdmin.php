<?php
namespace WNQ\Admin;
use WNQ\Models\MonthlySEO as M;
use WNQ\Models\Client;
if (!defined('ABSPATH')) exit;
require_once __DIR__.'/../includes/Models/MonthlySEO.php';

final class MonthlySEOAdmin
{
    public static function register(): void
    {
        add_action('admin_post_wnq_monthly_seo_save',[self::class,'save']);
        add_action('wnq_monthly_seo_rollover',[self::class,'cron']);
        add_action('init',static function () {
            if (!wp_next_scheduled('wnq_monthly_seo_rollover')) wp_schedule_event(time()+60,'hourly','wnq_monthly_seo_rollover');
        });
    }
    public static function cron(): void
    {
        try { M::rollover(); delete_option('wnq_monthly_seo_error'); }
        catch (\Throwable $e) { update_option('wnq_monthly_seo_error','Monthly rollover needs attention. Open SEO Portal to retry; existing work is preserved.',false); error_log('Monthly SEO rollover: '.$e->getMessage()); }
    }
    private static function access(): void
    {
        if (!current_user_can('manage_options') && !current_user_can('wnq_manage_portal')) wp_die('Not authorized.','',['response'=>403]);
    }
    private static function url(array $args=[]): string { return add_query_arg(array_merge(['page'=>'wnq-seo'],$args),admin_url('admin.php')); }
    private static function name(array $client): string { return ($client['company'] ?? '') ?: $client['name']; }
    private static function label(string $status): string { return ['pending'=>'Pending','in_progress'=>'In Progress','completed'=>'Completed','overdue'=>'Overdue','not_applicable'=>'Not Applicable'][$status] ?? $status; }
    private static function percent(?float $value): string { return $value===null?'—':$value.'%'; }
    private static function fields(string $op,string $client,string $month): void
    {
        wp_nonce_field('wnq_monthly_seo_save');
        foreach (['action'=>'wnq_monthly_seo_save','operation'=>$op,'client'=>$client,'month'=>$month] as $key=>$value) echo '<input type="hidden" name="'.esc_attr($key).'" value="'.esc_attr($value).'">';
    }
    public static function save(): void
    {
        self::access(); check_admin_referer('wnq_monthly_seo_save');
        try {
            $data=wp_unslash($_POST);$id=sanitize_text_field($data['client'] ?? '');$month=M::month($data['month'] ?? '');
            if (!Client::getSEOPlanClient($id)) throw new \InvalidArgumentException('This client must be active and on a Website + SEO or Website + SEO + PPC plan to use the SEO Portal.');
            M::install();$op=$data['operation'] ?? '';
            if ($op==='task') M::saveTask($id,absint($data['task_id'] ?? 0),$data);
            elseif ($op==='metrics') M::saveMetrics($id,$month,$data);
            elseif ($op==='plan') {
                $rows=$data['plan'] ?? [];
                if (!is_array($rows)) throw new \InvalidArgumentException('Invalid recurring plan.');
                if (trim($data['new_title'] ?? '')!=='') $rows[]=['template_key'=>'custom-'.wp_generate_uuid4(),'category'=>$data['new_category'] ?? '', 'title'=>$data['new_title'],'priority'=>$data['new_priority'] ?? 'medium','due_day'=>$data['new_day'] ?? 15,'enabled'=>1];
                M::savePlan($id,$rows);
            } else throw new \InvalidArgumentException('Unknown action.');
            wp_safe_redirect(self::url(['view'=>'client','client'=>$id,'month'=>$month,'saved'=>$op]));exit;
        } catch (\Throwable $e) { wp_die(esc_html($e->getMessage()),'SEO update not saved',['back_link'=>true,'response'=>400]); }
    }
    public static function render(): void
    {
        self::access();
        try {
            M::rollover();delete_option('wnq_monthly_seo_error');
            $month=M::month(isset($_GET['month']) ? sanitize_text_field(wp_unslash($_GET['month'])) : current_time('Y-m'));
            $id=isset($_GET['client'])?sanitize_text_field(wp_unslash($_GET['client'])):'';
            $client=$id?Client::getSEOPlanClient($id):null;
            if ($id && !$client) throw new \InvalidArgumentException('This client must be active and on a Website + SEO or Website + SEO + PPC plan to use the SEO Portal.');
            ?>
            <link rel="stylesheet" href="<?php echo esc_url(WNQ_PORTAL_URL.'assets/css/monthly-seo.css?v='.WNQ_PORTAL_VERSION); ?>">
            <div class="wrap mseo">
                <header class="mseo-header"><div><span class="mseo-eyebrow">ONGOING CLIENT OPERATIONS</span><h1><?php echo $client?esc_html(self::name($client)).' · SEO':'SEO Portal'; ?></h1><p>Plan the work. Record the results. Keep every month.</p></div><a class="button" href="<?php echo esc_url(self::url(['legacy'=>1,'view'=>$client?'client':'overview','client'=>$id])); ?>">Legacy setup &amp; history</a></header>
                <div class="mseo-toolbar"><form method="get"><input type="hidden" name="page" value="wnq-seo"><input type="hidden" name="view" value="<?php echo $client?'client':'overview'; ?>"><input type="hidden" name="client" value="<?php echo esc_attr($id); ?>"><label for="mseo-month">Working month</label> <input id="mseo-month" type="month" name="month" value="<?php echo esc_attr($month); ?>" required> <button class="button">View month</button></form><?php if ($client): ?><a href="<?php echo esc_url(self::url(['month'=>$month])); ?>">← All clients</a><button type="button" class="button" data-mseo-print>Print monthly summary</button><?php endif; ?></div>
                <p class="mseo-muted">Dates use <?php echo esc_html(wp_timezone_string()); ?>. Overdue is calculated automatically. Completion excludes Not Applicable tasks. Monthly metrics are recorded manually from your reporting tools.</p>
                <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success inline"><p>Saved. <?php if ($_GET['saved']==='plan') echo 'Recurring plan changes apply to newly generated months; existing work is unchanged.'; ?></p></div><?php endif; ?>
                <?php if ($client) self::client($client,$month); else self::overview($month); ?>
            </div><script src="<?php echo esc_url(WNQ_PORTAL_URL.'assets/js/monthly-seo.js?v='.WNQ_PORTAL_VERSION); ?>" defer></script>
            <?php
        } catch (\Throwable $e) { echo '<div class="wrap"><h1>SEO Portal</h1><div class="notice notice-error"><p>'.esc_html($e->getMessage()).'</p><p>Existing SEO records are preserved. Reload to retry.</p></div></div>'; }
    }
    private static function statsCards(array $values): void
    {
        echo '<div class="mseo-stats">';
        foreach ($values as $label=>$value) echo '<div class="mseo-stat"><strong>'.esc_html((string)$value).'</strong><span>'.esc_html($label).'</span></div>';
        echo '</div>';
    }
    private static function overview(string $month): void
    {
        $clients=Client::getSEOPlanClients();$today=current_time('Y-m-d');$summary=[];$agenda=[];$monthly=[];$all=[];
        foreach ($clients as $client) {
            $id=$client['client_id'];$tasks=M::tasks($id,$month);$through=M::tasks($id,$month,true);$stats=M::stats($tasks,$today);$ops=M::stats($through,$today);$cycles=M::history($id);
            $last=$ops['last_activity'];foreach ($cycles as $cycle) if (!empty($cycle['last_activity']) && (!$last || $cycle['last_activity']>$last)) $last=$cycle['last_activity'];
            $summary[]=['client'=>$client,'stats'=>$stats,'ops'=>$ops,'last'=>$last];
            $monthly=array_merge($monthly,$tasks);$all=array_merge($all,$through);
            foreach ($through as $task) if (!in_array($task['status'],['completed','not_applicable'],true)) $agenda[]=$task+['client_name'=>self::name($client)];
        }
        $ms=M::stats($monthly,$today);$os=M::stats($all,$today);
        self::statsCards(['Due today + overdue'=>$os['today'],'Due this week'=>$os['week'],'Overdue'=>$os['overdue'],'Monthly completion'=>self::percent($ms['percentage']),'Completed this cycle'=>$ms['completed']]);
        ?>
        <section class="mseo-panel"><div class="mseo-section-title"><h2>Work queue</h2><label>Client <select id="mseo-agenda-client"><option value="">All clients</option><?php foreach ($clients as $client) echo '<option value="'.esc_attr($client['client_id']).'">'.esc_html(self::name($client)).'</option>'; ?></select></label><label>Show <select id="mseo-agenda-filter"><option value="today">Today + overdue</option><option value="week">This week</option><option value="month">Selected month</option><option value="all">All open through this month</option></select></label></div>
        <div class="mseo-table-wrap"><table class="widefat mseo-table"><thead><tr><th>Client</th><th>Next action</th><th>Category</th><th>Due</th><th>Priority</th><th>Status</th></tr></thead><tbody>
        <?php usort($agenda,static fn($a,$b)=>[$a['due_date'],array_search($a['priority'],['high','medium','low']),$a['id']]<=>[$b['due_date'],array_search($b['priority'],['high','medium','low']),$b['id']]);
        $weekStart=(new \DateTimeImmutable($today))->modify('monday this week')->format('Y-m-d');$weekEnd=(new \DateTimeImmutable($weekStart))->modify('+6 days')->format('Y-m-d');
        foreach ($agenda as $task): $state=M::status($task,$today); ?>
        <tr data-mseo-agenda data-client="<?php echo esc_attr($task['client_id']); ?>" data-today="<?php echo $task['due_date']<=$today?'1':'0'; ?>" data-week="<?php echo $task['due_date']>=$weekStart && $task['due_date']<=$weekEnd?'1':'0'; ?>" data-month="<?php echo substr($task['due_date'],0,7)===$month?'1':'0'; ?>"><td><?php echo esc_html($task['client_name']); ?></td><td><a href="<?php echo esc_url(self::url(['view'=>'client','client'=>$task['client_id'],'month'=>$task['month_year']]).'#task-'.$task['id']); ?>"><?php echo esc_html($task['title']); ?></a></td><td><?php echo esc_html(M::categories()[$task['category']] ?? $task['category']); ?></td><td><?php echo esc_html($task['due_date']); ?></td><td><?php echo esc_html(ucfirst($task['priority'])); ?></td><td><span class="mseo-status <?php echo esc_attr($state); ?>"><?php echo esc_html(self::label($state)); ?></span></td></tr>
        <?php endforeach; ?></tbody></table></div><p id="mseo-agenda-empty" hidden>No open tasks in this view.</p><div class="mseo-pagination"><span id="mseo-agenda-count" role="status"></span><button type="button" class="button" id="mseo-agenda-prev">Previous</button><button type="button" class="button" id="mseo-agenda-next">Next</button></div></section>
        <div class="mseo-section-title"><h2>Clients · <?php echo esc_html($month); ?></h2><label>Find client <input type="search" id="mseo-client-search" placeholder="Company or client name"></label></div>
        <div class="mseo-clients"><?php foreach ($summary as $row): $client=$row['client'];$s=$row['stats'];$o=$row['ops'];$url=self::url(['view'=>'client','client'=>$client['client_id'],'month'=>$month]); ?>
            <article class="mseo-client" data-mseo-client="<?php echo esc_attr(strtolower(self::name($client))); ?>"><div class="mseo-section-title"><h3><?php echo esc_html(self::name($client)); ?></h3><span class="mseo-muted"><?php echo esc_html($client['status'] ?? 'active'); ?></span></div>
            <?php if (!empty($client['website'])): ?><a href="<?php echo esc_url($client['website']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($client['website']); ?></a><?php endif; ?>
            <p class="mseo-progress-label"><strong><?php echo self::percent($s['percentage']); ?></strong> · <?php echo $s['completed']; ?> completed / <?php echo $s['total']-$s['not_applicable']; ?> applicable tasks</p><progress max="100" value="<?php echo (int)$s['percentage']; ?>" aria-label="Monthly completion"></progress>
            <p><?php echo $o['today']; ?> today + overdue · <?php echo $o['week']; ?> this week · <strong><?php echo $o['overdue']; ?> overdue</strong></p>
            <p><strong>Next:</strong> <?php if ($o['next']): ?><a href="<?php echo esc_url(self::url(['view'=>'client','client'=>$client['client_id'],'month'=>$o['next']['month_year']]).'#task-'.$o['next']['id']); ?>"><?php echo esc_html($o['next']['title']); ?></a> · <?php echo esc_html($o['next']['due_date']); ?><?php else: echo $s['total']?'No open tasks.':'No cycle recorded for this month.'; endif; ?></p>
            <p class="mseo-muted">Last SEO activity: <?php echo esc_html($row['last'] ?: 'No work recorded yet'); ?></p><a class="button button-primary" href="<?php echo esc_url($url); ?>">Manage SEO →</a></article>
        <?php endforeach; ?></div>
        <?php if (!$clients) echo '<p>No active SEO-plan clients. Set the client’s status to Active and its tier to Website + SEO or Website + SEO + PPC in the shared client profile.</p>'; ?>
        <?php
    }
    private static function client(array $client,string $month): void
    {
        $id=$client['client_id'];$today=current_time('Y-m-d');$cycle=M::cycle($id,$month);$tasks=M::tasks($id,$month);$stats=M::stats($tasks,$today);
        if (!$cycle) { echo '<div class="mseo-panel"><h2>No monthly cycle recorded</h2><p>New cycles are generated automatically for active clients in the current month. Earlier checklist work remains in Legacy setup &amp; history.</p></div>'; self::history($id);return; }
        self::statsCards(['Monthly completion'=>self::percent($stats['percentage']),'Completed'=>$stats['completed'],'Due this week'=>$stats['week'],'Overdue'=>$stats['overdue'],'Not Applicable'=>$stats['not_applicable']]);
        ?><nav class="mseo-tabs"><a href="#mseo-tasks">Monthly tasks</a><a href="#mseo-semrush">SEMrush</a><a href="#mseo-metrics">Results &amp; metrics</a><a href="#mseo-summary">Monthly summary</a><a href="#mseo-history">History</a><a href="#mseo-plan">Recurring plan</a></nav>
        <section id="mseo-tasks"><div class="mseo-section-title"><h2>Monthly work · <?php echo esc_html($month); ?></h2><label>Task status <select id="mseo-task-filter"><option value="all">All tasks</option><option value="open">Open tasks</option><?php foreach (['pending','in_progress','completed','overdue','not_applicable'] as $status) echo '<option value="'.esc_attr($status).'">'.esc_html(self::label($status)).'</option>'; ?></select></label></div>
        <?php foreach (M::categories() as $category=>$label): $group=array_values(array_filter($tasks,static fn($task)=>$task['category']===$category));$groupStats=M::stats($group,$today); ?>
            <details class="mseo-category" id="<?php echo $category==='semrush'?'mseo-semrush':'category-'.esc_attr($category); ?>" <?php echo $category==='semrush'?'open':''; ?>><summary><span><?php echo esc_html($label); ?></span><small><?php echo $groupStats['completed']; ?>/<?php echo count($group); ?> completed · <?php echo $groupStats['overdue']; ?> overdue</small></summary>
            <?php if ($category==='semrush'): $sem=json_decode($cycle['metrics_json'],true) ?: []; ?><div class="mseo-sem-stats"><?php foreach (['site_health'=>'Site health','rank_gains'=>'Ranking gains','rank_losses'=>'Ranking losses','referring_domains'=>'Referring domains'] as $key=>$title): ?><div><strong><?php echo esc_html(isset($sem[$key])?(string)$sem[$key]:'—'); ?></strong><span><?php echo esc_html($title); ?></span></div><?php endforeach; ?></div><p><a href="#mseo-metrics">Record SEMrush metrics, competitors and opportunities →</a></p><?php endif; ?>
            <?php if (!$group) echo '<p>No tasks scheduled in this category. Adjust the recurring plan for future months.</p>'; ?>
            <?php foreach ($group as $task): $state=M::status($task,$today); ?><details class="mseo-task" id="task-<?php echo (int)$task['id']; ?>" data-task-status="<?php echo esc_attr($state); ?>"><summary><span><?php echo esc_html($task['title']); ?><small>Due <?php echo esc_html($task['due_date']); ?> · <?php echo esc_html(ucfirst($task['priority'])); ?> priority<?php if ($task['completed_date']) echo ' · Completed '.esc_html($task['completed_date']); ?></small></span><span class="mseo-status <?php echo esc_attr($state); ?>"><?php echo esc_html(self::label($state)); ?></span></summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php self::fields('task',$id,$month); ?><input type="hidden" name="task_id" value="<?php echo (int)$task['id']; ?>"><input type="hidden" name="revision" value="<?php echo (int)$task['revision']; ?>"><div class="mseo-fields">
                <label>Status<select name="status"><?php foreach (['pending','in_progress','completed','not_applicable'] as $status): ?><option value="<?php echo esc_attr($status); ?>" <?php selected($task['status'],$status); ?>><?php echo esc_html(self::label($status)); ?></option><?php endforeach; ?></select></label>
                <label>Priority<select name="priority"><?php foreach (['high','medium','low'] as $priority): ?><option <?php selected($task['priority'],$priority); ?> value="<?php echo esc_attr($priority); ?>"><?php echo esc_html(ucfirst($priority)); ?></option><?php endforeach; ?></select></label>
                <label>Due date<input type="date" name="due_date" value="<?php echo esc_attr($task['due_date']); ?>" required></label><label>Completion date<input type="date" name="completed_date" max="<?php echo esc_attr($today); ?>" value="<?php echo esc_attr($task['completed_date'] ?? ''); ?>"><small>Defaults to today when marked Completed.</small></label></div>
                <label>Work notes / evidence / URLs<textarea name="notes" rows="3"><?php echo esc_textarea($task['notes']); ?></textarea></label><button class="button button-primary">Save task</button>
            </form></details><?php endforeach; ?></details>
        <?php endforeach; ?></section>
        <?php self::metricsForm($id,$month,$cycle); self::summary($client,$month,$cycle,$tasks);self::history($id);self::plan($id,$month);
    }
    private static function metricsForm(string $id,string $month,array $cycle): void
    {
        $metrics=json_decode($cycle['metrics_json'],true) ?: [];$notes=json_decode($cycle['narrative_json'],true) ?: [];
        ?><details class="mseo-panel" id="mseo-metrics"><summary>Record monthly results &amp; opportunities</summary><p>Use the selected calendar month for GSC, leads, and output counts. SEMrush health and referring domains are month-end snapshots. Leave unavailable values blank. CTR uses percentages (for example, 3.5).</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php self::fields('metrics',$id,$month); ?><input type="hidden" name="revision" value="<?php echo (int)$cycle['revision']; ?>"><div class="mseo-fields">
        <?php foreach (M::metrics() as $key=>[$label,$type,$max]): ?><label><?php echo esc_html($label); ?><input name="<?php echo esc_attr($key); ?>" type="number" min="0" step="<?php echo $type==='integer'?'1':'0.01'; ?>" <?php if ($max!==null) echo 'max="'.(int)$max.'"'; ?> value="<?php echo esc_attr($metrics[$key] ?? ''); ?>" placeholder="Not recorded"></label><?php endforeach; ?></div><div class="mseo-fields mseo-notes">
        <?php foreach (M::narratives() as $key=>$label): ?><label><?php echo esc_html($label); ?><textarea name="<?php echo esc_attr($key); ?>" rows="4"><?php echo esc_textarea($notes[$key] ?? ''); ?></textarea></label><?php endforeach; ?></div><button class="button button-primary">Save monthly results</button></form></details><?php
    }
    private static function summary(array $client,string $month,array $cycle,array $tasks): void
    {
        $metrics=json_decode($cycle['metrics_json'],true) ?: [];$notes=json_decode($cycle['narrative_json'],true) ?: [];
        $previousMonth=(new \DateTimeImmutable($month.'-01'))->modify('-1 month')->format('Y-m');$previous=M::cycle($client['client_id'],$previousMonth);$prior=$previous?(json_decode($previous['metrics_json'],true) ?: []):[];
        $completed=array_filter($tasks,static fn($task)=>$task['status']==='completed');
        ?><section class="mseo-panel" id="mseo-summary"><span class="mseo-eyebrow">MONTHLY SEO SUMMARY</span><h2><?php echo esc_html(self::name($client).' · '.$month); ?></h2><p><?php echo count($completed); ?> tasks completed in this cycle. Comparison: <?php echo esc_html($previousMonth); ?>. Lower average position is better; CTR changes are percentage points.</p><div class="mseo-table-wrap"><table class="widefat mseo-table"><thead><tr><th>Performance / work delivered</th><th>This month</th><th>Previous month</th><th>Change</th></tr></thead><tbody>
        <?php foreach (M::metrics() as $key=>[$label]): $value=$metrics[$key] ?? null;$old=$prior[$key] ?? null; ?><tr><th><?php echo esc_html($label); ?></th><td><?php echo $value===null?'Not recorded':esc_html((string)$value); ?></td><td><?php echo $old===null?'Not recorded':esc_html((string)$old); ?></td><td><?php echo esc_html($key==='gsc_ctr' && $value!==null && $old!==null ? round($value-$old,2).' pp' : M::comparison($value,$old)); ?></td></tr><?php endforeach; ?></tbody></table></div>
        <div class="mseo-summary-notes"><?php foreach (M::narratives() as $key=>$label): ?><article><h3><?php echo esc_html($label); ?></h3><p><?php echo nl2br(esc_html(($notes[$key] ?? '') ?: 'Not recorded yet.')); ?></p></article><?php endforeach; ?></div>
        <h3>Completed work</h3><?php if (!$completed) echo '<p>No completed tasks recorded for this cycle.</p>'; ?><ul><?php foreach ($completed as $task): ?><li><strong><?php echo esc_html($task['title']); ?></strong> · <?php echo esc_html(M::categories()[$task['category']]); ?> · <?php echo esc_html($task['completed_date'] ?? ''); ?><?php if ($task['notes']) echo '<p>'.nl2br(esc_html($task['notes'])).'</p>'; ?></li><?php endforeach; ?></ul></section><?php
    }
    private static function history(string $id): void
    {
        ?><section class="mseo-panel" id="mseo-history"><h2>Monthly history</h2><p>Each month keeps its own work, completion dates, notes and metrics. Blank metrics mean no value has been recorded.</p><div class="mseo-table-wrap"><table class="widefat mseo-table"><thead><tr><th>Month</th><th>Completed / applicable</th><th>GSC clicks</th><th>Impressions</th><th>Avg. position</th><th>Organic leads</th><th>Referring domains</th></tr></thead><tbody>
        <?php foreach (M::history($id) as $cycle): $values=json_decode($cycle['metrics_json'],true) ?: [];$s=M::stats(M::tasks($id,$cycle['month_year']),current_time('Y-m-d')); ?><tr><td><a href="<?php echo esc_url(self::url(['view'=>'client','client'=>$id,'month'=>$cycle['month_year']])); ?>"><?php echo esc_html($cycle['month_year']); ?></a></td><td><?php echo $s['completed'].' / '.($s['total']-$s['not_applicable']); ?></td><?php foreach (['gsc_clicks','gsc_impressions','gsc_position','organic_leads','referring_domains'] as $key) echo '<td>'.esc_html(isset($values[$key])?(string)$values[$key]:'—').'</td>'; ?></tr><?php endforeach; ?></tbody></table></div><p><a href="<?php echo esc_url(self::url(['legacy'=>1,'view'=>'client','client'=>$id,'service'=>'monthly','month'=>current_time('Y-m')])); ?>">View earlier checklist and report history →</a></p></section><?php
    }
    private static function plan(string $id,string $month): void
    {
        ?><details class="mseo-panel" id="mseo-plan"><summary>Reusable monthly plan</summary><p>Enabled tasks generate automatically for each new month. Changes affect future cycles only. Existing tasks and historical notes are preserved. Due days beyond the end of a month use its final day.</p><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php self::fields('plan',$id,$month); ?><div class="mseo-table-wrap"><table class="widefat mseo-table"><thead><tr><th>Enabled</th><th>Task</th><th>Category</th><th>Due day</th><th>Priority</th></tr></thead><tbody>
        <?php foreach (M::plan($id) as $i=>$row): ?><tr><td><input type="hidden" name="plan[<?php echo $i; ?>][template_key]" value="<?php echo esc_attr($row['template_key']); ?>"><input type="checkbox" name="plan[<?php echo $i; ?>][enabled]" value="1" aria-label="Enable <?php echo esc_attr($row['title']); ?>" <?php checked(!empty($row['enabled'])); ?>></td><td><input name="plan[<?php echo $i; ?>][title]" value="<?php echo esc_attr($row['title']); ?>" aria-label="Task title" required maxlength="255"></td><td><select name="plan[<?php echo $i; ?>][category]" aria-label="Category"><?php foreach (M::categories() as $key=>$label): ?><option value="<?php echo esc_attr($key); ?>" <?php selected($row['category'],$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></td><td><input type="number" min="1" max="31" name="plan[<?php echo $i; ?>][due_day]" value="<?php echo (int)$row['due_day']; ?>" aria-label="Due day" required></td><td><select name="plan[<?php echo $i; ?>][priority]" aria-label="Priority"><?php foreach (['high','medium','low'] as $priority): ?><option <?php selected($row['priority'],$priority); ?>><?php echo esc_html($priority); ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?></tbody></table></div>
        <h3>Add a recurring task</h3><div class="mseo-fields"><label>Task title<input name="new_title" maxlength="255" placeholder="Optional new recurring task"></label><label>Category<select name="new_category"><?php foreach (M::categories() as $key=>$label) echo '<option value="'.esc_attr($key).'">'.esc_html($label).'</option>'; ?></select></label><label>Due day<input type="number" name="new_day" value="15" min="1" max="31"></label><label>Priority<select name="new_priority"><option>high</option><option selected>medium</option><option>low</option></select></label></div><button class="button button-primary">Save recurring plan</button></form></details><?php
    }
}

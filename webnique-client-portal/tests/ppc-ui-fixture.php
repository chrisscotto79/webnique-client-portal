<?php
/** CLI-only synthetic data, never loaded by WordPress. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
require __DIR__.'/ppc-phase1-regression.php';
ob_end_clean();
require dirname(__DIR__).'/admin/PpcIntelligenceAdmin.php';
function esc_html($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function esc_attr($v): string { return esc_html($v); }
function esc_url($v): string { return esc_html($v); }
function number_format_i18n($v,$places=0): string { return number_format($v,$places); }
$render=new ReflectionMethod(WNQ\Admin\PpcIntelligenceAdmin::class,'renderAdvancedModule');
$styles=new ReflectionMethod(WNQ\Admin\PpcIntelligenceAdmin::class,'styles');
$rows=[];
for($i=1;$i<=125;$i++) $rows[]=['ngram'=>'Search theme '.$i,'size'=>2,'queries'=>3,'impressions'=>400,'clicks'=>35,'cost'=>80,'conversions'=>4,'conversion_rate'=>4/35,'cpa'=>20,'classification'=>'high_performing_intent','examples'=>['<script>window.injected=true</script>','Local search example'],'client_rule_context'=>[]];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PPC QA fixture</title>
<style>body{margin:0;padding:24px;background:#f0f2f5;font:14px system-ui}*{box-sizing:border-box}button,input,select{font:inherit;padding:7px;border:1px solid #b8c5d3;border-radius:5px;background:white}button{cursor:pointer}.wnq-ppc-intelligence{margin:auto}h1{margin-top:0}@media(max-width:600px){body{padding:10px}}</style>
<?php $styles->invoke(null); ?><style><?php readfile(dirname(__DIR__).'/assets/admin/ppc-intelligence.css'); ?></style></head><body>
<main class="wnq-ppc-intelligence"><div class="wnq-ppc-hero"><div><h1>PPC Intelligence</h1><p>Search campaigns · Test client · Read only</p></div></div>
<nav class="wnq-workspace-tabs" role="tablist" aria-label="PPC workspaces">
<?php foreach(['overview'=>'Overview','performance'=>'Performance','search'=>'Search & creative','quality'=>'Lead quality','control'=>'Review & memory'] as $id=>$label): ?>
<button type="button" role="tab" id="ppc-tab-<?php echo $id; ?>" aria-controls="ppc-workspace-<?php echo $id; ?>" data-wnq-workspace-tab="<?php echo $id; ?>"><?php echo esc_html($label); ?></button>
<?php endforeach; ?></nav>
<section data-wnq-workspace="overview" id="ppc-workspace-overview" class="wnq-workspace-panel"><article class="wnq-module"><h3>What needs attention?</h3><p>Review an opportunity, then open its evidence.</p><a href="#ppc-ngrams">Review search patterns</a></article></section>
<section data-wnq-workspace="performance" id="ppc-workspace-performance" class="wnq-workspace-panel"><?php $render->invoke(null,'quality_score',['available'=>false,'status'=>'unavailable','message'=>'Keyword quality is temporarily unavailable.'],'fixture'); ?></section>
<section data-wnq-workspace="search" id="ppc-workspace-search" class="wnq-workspace-panel"><?php $render->invoke(null,'ngrams',['available'=>true,'status'=>'ready','period'=>'Aug 7 – Sep 5, 2026 · Search account','items'=>$rows,'message'=>'Patterns overlap; do not add their totals.'],'fixture'); ?></section>
<section data-wnq-workspace="quality" id="ppc-workspace-quality" class="wnq-workspace-panel"><article class="wnq-module">Lead quality evidence</article></section>
<section data-wnq-workspace="control" id="ppc-workspace-control" class="wnq-workspace-panel"><article class="wnq-module">Internal review only. Google Ads execution is disabled.</article></section>
</main><script><?php readfile(dirname(__DIR__).'/assets/admin/ppc-intelligence.js'); ?></script></body></html>

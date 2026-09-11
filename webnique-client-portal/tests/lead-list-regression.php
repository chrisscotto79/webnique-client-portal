<?php
/** Reuse offline GHL fixtures. No real records are deleted or sent. */
if (PHP_SAPI !== 'cli') { exit; }
ob_start(); require __DIR__ . '/lead-ghl-regression.php'; ob_end_clean();
define('WNQ_PORTAL_VERSION', 'fixture');
function sanitize_key($v) { return preg_replace('/[^a-z_]/', '', $v); }
function sanitize_text_field($v) { return trim(strip_tags($v)); }
function sanitize_textarea_field($v) { return sanitize_text_field($v); }
function wp_unslash($v) { return $v; }
function absint($v) { return abs((int)$v); }
function selected($a,$b) { if ($a === $b) { echo 'selected'; } }
function plugins_url($v,$file) { return 'https://example.com/' . $v; }
function wp_nonce_url($v,$action) { return $v; }
function set_transient($key,$value,$ttl) { $GLOBALS['notice']=$value; }
function wp_safe_redirect($url) { throw new RuntimeException('redirect'); }
require __DIR__ . '/../admin/LeadBrowserAdmin.php';
resetFixture(); $allowed=true; $validNonce=true;
$filter = new ReflectionMethod(WNQ\Models\Lead::class,'listFilters');
$where=[]; $params=[];
$filter->invokeArgs(null,[['zip'=>'01234','company_fit'=>'chain','max_reviews'=>49,'seo_issues_min'=>3,'has_phone'=>true,'no_website'=>true,'no_email'=>true], &$where, &$params]);
check($params === ['01234','chain',49,3], 'Filter parameters preserve ZIP and threshold values');
check(in_array('seo_checked = 1 AND seo_score >= %d',$where,true),'Unassessed SEO excluded');
check(in_array("phone != ''",$where,true) && in_array("email = ''",$where,true),'Calling filters');
ob_start(); WNQ\Admin\LeadBrowserAdmin::leads(); $html=ob_get_clean();
foreach (['max_reviews','seo_issues_min','company_fit','no_website','has_phone','confirmation','DELETE ALL','lf-ghl-list'] as $field) {
    check(str_contains($html,$field),'List renders ' . $field);
}
$_POST=['operation'=>'delete_all','confirmation'=>'WRONG'];
try { WNQ\Admin\LeadBrowserAdmin::manage(); } catch (RuntimeException $e) { check($e->getMessage()==='redirect','Handler redirects'); }
check(str_contains($GLOBALS['notice'],'confirmation required'),'Server enforces exact deletion confirmation');
$validNonce=false;
try { WNQ\Admin\LeadBrowserAdmin::manage(); check(false,'Bad nonce accepted'); } catch (RuntimeException $e) { check($e->getMessage()==='nonce rejected','Destructive handler requires nonce'); }
if (($argv[1] ?? '') === '--list-render') { echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body>' . $html . '</body></html>'; }
else { echo "PASS: lead-list rendering, filters, confirmation and nonce checks; all database operations mocked.\n"; }

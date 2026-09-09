<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__.'/');
define('MINUTE_IN_SECONDS',60);
define('ARRAY_A','ARRAY_A');
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function wp_json_encode($v){return json_encode($v);}
function is_wp_error($v){return false;}
function wp_remote_post($url,$args){$GLOBALS['sent']=[$url,$args];return $GLOBALS['response'];}
function wp_remote_retrieve_response_code($v){return $v['code'];}
function wp_remote_retrieve_body($v){return $v['body'];}
function sanitize_text_field($v){return (string)$v;}
function esc_url_raw($v){return (string)$v;}
function check_admin_referer($v){$GLOBALS['nonce']=true;}
function current_user_can($v){return $GLOBALS['allowed']??true;}
function wp_die($message){throw new RuntimeException($message);}
final class DatabaseFixture {
    public $prefix='wp_', $insert_id=42, $reads=[], $queries=[], $row=null, $writes=0;
    function prepare($sql,...$args){return $sql;}
    function get_row($sql,$format){$this->reads[]=$sql;return $this->row;}
    function query($sql){$this->queries[]=$sql;return 1;}
    function insert($table,$values){return false;}
    function update(...$args){$this->writes++;return 1;}
    function delete(...$args){$this->writes++;return 1;}
}
$wpdb=new DatabaseFixture();
require dirname(__DIR__).'/includes/Services/BlogPublisher.php';
require dirname(__DIR__).'/includes/Models/BlogScheduler.php';
require dirname(__DIR__).'/includes/Core/SEOOSBootstrap.php';
function invoke($method,...$args){return (new ReflectionMethod(\WNQ\Services\BlogPublisher::class,$method))->invoke(null,...$args);}
$agent=['site_url'=>'https://client.example','api_key'=>'fixture'];
foreach (['<html>Login</html>','null','[]','{"success":false,"post_id":123}','{"post_id":0}','{"post_id":"bad"}'] as $body) {
    $response=['code'=>200,'body'=>$body];
    check(!invoke('pushToAgent',$agent,[])['success'],'Invalid successful HTTP response must not mark published');
}
$response=['code'=>201,'body'=>'{"post_id":123,"post_url":"https://client.example/custom/post/"}'];
check(invoke('pushToAgent',$agent,[])['success'],'Confirmed post accepted');
check($sent[1]['redirection']===0,'Do not forward agent credentials through redirects');
check(invoke('resolvePublishedUrl','https://client.example/custom/post/','https://client.example/blog/Post/')==='https://client.example/custom/post/','Agent permalink wins');
check(invoke('resolvePublishedUrl','','https://client.example/blog/Post/')==='https://client.example/blog/Post/','Legacy missing permalink fallback');
$wpdb->reads=[];
check(invoke('getActiveAgent','client',123)===null && count($wpdb->reads)===1,'Missing explicitly assigned agent never falls back');
check(str_contains($wpdb->reads[0],'client_id = %s'),'Assigned site is client scoped');
check(\WNQ\Models\BlogScheduler::addPost('client',['title'=>'Draft'])===0,'Failed inserts do not reuse stale insert ID');
\WNQ\Models\BlogScheduler::deletePosts([1,2],'client');
\WNQ\Models\BlogScheduler::deletePostsByClient('client');
foreach ($wpdb->queries as $sql)check(str_contains($sql,"status NOT IN ('generating', 'publishing')"),'Bulk deletions protect processing jobs');
foreach (['handleBlogSaveFeaturedImage','handleBlogDeletePost','handleBlogUpdatePost'] as $method) {
    $_POST=['post_id'=>1,'client_id'=>'other'];
    $wpdb->row=['id'=>1,'client_id'=>'client','status'=>'pending'];
    try {\WNQ\Core\SEOOSBootstrap::$method();throw new LogicException('Accepted wrong client');}
    catch(RuntimeException $e){check($e->getMessage()==='Invalid post','Wrong client rejected');}
    $_POST['client_id']='client';$wpdb->row['status']='publishing';
    try {\WNQ\Core\SEOOSBootstrap::$method();throw new LogicException('Accepted running job');}
    catch(RuntimeException $e){check(str_contains($e->getMessage(),'being processed'),'Running job guarded');}
}
check($wpdb->writes===0 && $GLOBALS['nonce'],'Guards run before modifications with nonce checks');
echo "Blog Scheduler regression checks passed.\n";

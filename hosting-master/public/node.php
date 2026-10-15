<?php
declare(strict_types=1);
// Stateless node-to-master endpoint. Supports both cPanel and DirectAdmin.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
function nodeError(int $code, string $error): never {
    http_response_code($code);
    echo json_encode(['error'=>$error]);
    exit;
}
if (($_SERVER['REQUEST_METHOD']??'') !== 'POST') nodeError(405,'method_not_allowed');
if ((($_SERVER['HTTPS']??'')!=='on' && ($_SERVER['SERVER_PORT']??'')!=='443')) nodeError(403,'https_required');
$root=realpath((string)($_SERVER['DOCUMENT_ROOT']??''));
if (!$root || basename($root)!=='public_html') nodeError(503,'invalid_document_root');
$path=dirname($root).'/.helixpanel-master';
if (!is_file($path.'/config.php') || !is_file($path.'/store.php')) nodeError(503,'master_not_configured');
$action=$_GET['action']??'';
if (!in_array($action,['enroll','heartbeat'],true)) nodeError(404,'unknown_action');
if (!str_starts_with((string)($_SERVER['CONTENT_TYPE']??''),'application/json')) nodeError(415,'json_required');
$length=(int)($_SERVER['CONTENT_LENGTH']??0);
if ($length>2048) nodeError(413,'body_too_large');
$raw=file_get_contents('php://input',false,null,0,2049);
if ($raw===false || strlen($raw)>2048) nodeError(413,'body_too_large');
$body=json_decode($raw,true);
if (!is_array($body) || array_is_list($body)) nodeError(400,'invalid_json');
try {
    define('HELIX_MASTER_INTERNAL',true);
    $config=require $path.'/config.php';
    if (!is_array($config) || ($config['mode']??'')!=='standalone') nodeError(503,'not_standalone_master');
    require $path.'/store.php';
    $store=new HelixMasterStore($config);
    $result=$store->agentRequest($action,$body);
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    // Do not expose database errors or private paths to node hosts.
    error_log('HelixPanel node API error: '.get_class($e));
    nodeError(403,'invalid_node_credentials_or_service_unavailable');
}

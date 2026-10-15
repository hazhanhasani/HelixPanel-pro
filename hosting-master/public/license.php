<?php
declare(strict_types=1);
// Standard HelixPanel license enrollment endpoint for hosting editions.
// Public GET supplies only a non-secret installation ID. POST only accepts
// an Ed25519-signed vendor license for that exact installation.
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function hpLicRespond(int $status,array $payload): never {
 http_response_code($status);echo json_encode($payload,JSON_THROW_ON_ERROR);exit;
}
if ((($_SERVER['HTTPS']??'')!=='on' && (int)($_SERVER['SERVER_PORT']??0)!==443)) hpLicRespond(403,['error'=>'https_required']);
$root=realpath((string)($_SERVER['DOCUMENT_ROOT']??''));
if (!$root || basename($root)!=='public_html') hpLicRespond(503,['error'=>'bad_docroot']);
$private=dirname($root).'/.helixpanel-master';
if (!is_file($private.'/config.php') || is_link($private.'/config.php') || !is_file($private.'/store.php')) hpLicRespond(503,['error'=>'not_installed']);
define('HELIX_MASTER_INTERNAL',true);
$cfg=require $private.'/config.php';
if (!is_array($cfg) || ($cfg['mode']??'')!=='standalone') hpLicRespond(503,['error'=>'not_standalone']);
require $private.'/store.php';
try {
 $store=new HelixMasterStore($cfg);
 $id=$store->installationID();
 if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') hpLicRespond(200,['product'=>'helixpanel','installation_id'=>$id]);
 if (($_SERVER['REQUEST_METHOD']??'')!=='POST') hpLicRespond(405,['error'=>'method_not_allowed']);
 if (empty($cfg['license_public_key']) || !function_exists('sodium_crypto_sign_verify_detached')) hpLicRespond(503,['error'=>'vendor_public_key_unavailable']);
 if ((int)($_SERVER['CONTENT_LENGTH']??0)>12000) hpLicRespond(413,['error'=>'too_large']);
 $raw=file_get_contents('php://input',false,null,0,12001);
 if (!is_string($raw)||strlen($raw)>12000) hpLicRespond(413,['error'=>'too_large']);
 $data=json_decode($raw,true);
 $token=is_array($data)?($data['license_key']??null):null;
 if (!is_string($token)||strlen($token)>10000) hpLicRespond(400,['error'=>'invalid_request']);
 // Verify using the exact signer accepted by the master itself.
 $previous=$store->license();
 $tempCfg=$cfg;
 $tempCfg['license_key']=$token;
 $candidate=new HelixMasterStore($tempCfg);
 $status=$candidate->license();
 if (!$status['active'] || ($status['installation_id']??'')!==$id) hpLicRespond(403,['error'=>'invalid_license']);
 if (($previous['active']??false) &&
    (($status['expires_at']??0)<($previous['expires_at']??0) ||
      (($status['expires_at']??0)===($previous['expires_at']??0) &&
       (($status['max_nodes']??0)<($previous['max_nodes']??0) || ($status['max_users']??0)<($previous['max_users']??0))))) {
    hpLicRespond(409,['error'=>'older_license']);
 }
 $cfg['license_key']=$token;
 $contents="<?php\ndeclare(strict_types=1);\nreturn ".var_export($cfg,true).";\n";
 $temp=$private.'/.license-config-'.bin2hex(random_bytes(8)).'.php';
 $fh=@fopen($temp,'x');
 if (!$fh) hpLicRespond(503,['error'=>'storage_unavailable']);
 @chmod($temp,0600);
 $bytes=fwrite($fh,$contents);fflush($fh);fclose($fh);
 if ($bytes!==strlen($contents) || !rename($temp,$private.'/config.php')) {
   @unlink($temp);hpLicRespond(503,['error'=>'storage_unavailable']);
 }
 hpLicRespond(200,['activated'=>true,'license_id'=>$status['license_id']??'','expires_at'=>$status['expires_at']]);
} catch(Throwable $e) {
 error_log('HelixPanel license delivery: '.get_class($e));
 hpLicRespond(503,['error'=>'activation_unavailable']);
}

<?php
declare(strict_types=1);
// End-to-end PHP/MySQL master tests in GitHub CI.
define('HELIX_MASTER_INTERNAL', true);
require dirname(__DIR__) . '/hosting-master/private/store.php';

$pdo = new PDO(getenv('HP_TEST_DSN'), getenv('HP_TEST_USER'), getenv('HP_TEST_PASSWORD'), [
 PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$sql = file_get_contents(dirname(__DIR__).'/hosting-master/schema.sql');
foreach (explode(';', $sql) as $stmt) {
 $parts = array_filter(explode("\n", $stmt), fn(string $x) => !str_starts_with(trim($x), '--'));
 $command = trim(implode("\n", $parts));
 if ($command !== '') $pdo->exec($command);
}
$cfg = [
 'db_dsn' => getenv('HP_TEST_DSN'),
 'db_user' => getenv('HP_TEST_USER'),
 'db_password' => getenv('HP_TEST_PASSWORD'),
 'license_key' => '', 'license_public_key' => '',
];
function ensure(bool $yes, string $what): void {
 if (!$yes) {fwrite(STDERR, "FAILED: $what\n"); exit(1);}
}
$store = new HelixMasterStore($cfg);
$id=$store->installationID();
ensure(preg_match('/^[0-9a-f]{32}$/D',$id)===1,'installation identity generated');
ensure($id===$store->installationID(),'identity stable');
$node=$store->handle('POST','/api/v1/nodes',['name'=>'node-DE-1','region'=>'DE']);
ensure(preg_match('/^[a-f0-9]{32}$/D',$node['id'])===1,'node creation');
try {$store->handle('POST','/api/v1/nodes',['name'=>'node-DE-2','region'=>'DE']); ensure(false,'license node limits');}
catch(RuntimeException $e) {}
$activation=$store->handle('POST','/api/v1/nodes/'.$node['id'].'/enrollment');
ensure(strlen($activation['enrollment_token'])===64,'one-time pairing code');
try {$store->agentRequest('enroll',['node_id'=>$node['id'],'enrollment_token'=>str_repeat('a',64)]);ensure(false,'wrong enrollment code');}
catch(RuntimeException $e) {}
$pair=$store->agentRequest('enroll',['node_id'=>$node['id'],'enrollment_token'=>$activation['enrollment_token']]);
ensure(strlen($pair['agent_token'])===64,'node receives agent key');
try {$store->agentRequest('enroll',['node_id'=>$node['id'],'enrollment_token'=>$activation['enrollment_token']]);ensure(false,'token replay');}
catch(RuntimeException $e) {}
$beat=$store->agentRequest('heartbeat',['node_id'=>$node['id'],'agent_token'=>$pair['agent_token'],'agent_version'=>'v0.2.0-preview','load_1'=>1.5]);
ensure($beat['ok']===true,'heartbeat accepted');
ensure($store->nodes()[0]['status']==='online','node online status');
$reset=$store->handle('POST','/api/v1/nodes/'.$node['id'].'/enrollment');
try {$store->agentRequest('heartbeat',['node_id'=>$node['id'],'agent_token'=>$pair['agent_token'],'agent_version'=>'v0.2.0-preview','load_1'=>1.5]);ensure(false,'revoked agent token');}
catch(RuntimeException $e) {}
ensure(count($store->nodes())===1,'node list');
$user=$store->handle('POST','/api/v1/users',['name'=>'user01','quota_gb'=>20,'expires_at'=>gmdate('c',time()+3600)]);
ensure(!empty($user['id']),'add user');
for ($i=2;$i<=25;$i++) {
 $store->handle('POST','/api/v1/users',[
  'name'=>sprintf('user%02d',$i),'quota_gb'=>10,'expires_at'=>gmdate('c',time()+3600)
 ]);
}
ensure(count($store->users())===25,'exactly 25 free users accepted');
try {
 $store->handle('POST','/api/v1/users',[
   'name'=>'user26','quota_gb'=>10,'expires_at'=>gmdate('c',time()+3600)
 ]);
 ensure(false,'26th free user should be rejected');
} catch(RuntimeException $e) {
 ensure(str_contains($e->getMessage(),'سقف کاربران'),'free user limit rejected as expected');
}
ensure($store->handle('GET','/api/v1/summary')['nodes']===1,'summary');
ensure($store->license()['active']===false,'no signed license is community');
echo "OK: standalone MySQL PHP master, enrollment, replay defense, revoke, heartbeat and licensing limits\n";

<?php
declare(strict_types=1);
/**
 * Shared-hosting standalone control-plane storage & narrow node API.
 * Intended to be installed OUTSIDE public_html alongside config.php.
 * This file is not a VPN proxy and does not handle subscriber traffic.
 */
if (!defined('HELIX_MASTER_INTERNAL')) { http_response_code(404); exit; }

final class HelixMasterStore {
    private PDO $db;
    private array $cfg;

    public function __construct(array $cfg) {
        $this->cfg = $cfg;
        $dsn = (string)($cfg['db_dsn'] ?? '');
        if (!str_starts_with($dsn, 'mysql:') || !str_contains($dsn, 'charset=utf8mb4')) {
            throw new RuntimeException('MySQL DSN with charset=utf8mb4 is required');
        }
        $this->db = new PDO($dsn, (string)($cfg['db_user'] ?? ''), (string)($cfg['db_password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 4,
        ]);
        $this->db->exec("SET time_zone = '+00:00'");
    }
    private function query(string $sql, array $params = []): PDOStatement {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    public function installationID(): string {
        $this->query('INSERT IGNORE INTO hp_master_meta(meta_key, meta_value) VALUES (?, ?)', [
            'installation_id', bin2hex(random_bytes(16)),
        ]);
        $id = (string)$this->query('SELECT meta_value FROM hp_master_meta WHERE meta_key=?', ['installation_id'])->fetchColumn();
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new RuntimeException('Installation identity invalid');
        return $id;
    }
    public function license(): array {
        $id = $this->installationID();
        $fallback = [
            'active'=>false, 'tier'=>'community', 'installation_id'=>$id,
            'max_nodes'=>1, 'max_users'=>25, 'reason'=>'no valid license',
        ];
        $key = (string)($this->cfg['license_key'] ?? '');
        $pub = (string)($this->cfg['license_public_key'] ?? '');
        if ($key === '' || $pub === '' || !function_exists('sodium_crypto_sign_verify_detached')) {
            return $fallback;
        }
        $parts = explode('.', $key);
        if (count($parts)!==2 || strlen($parts[0])>8192) return $fallback;
        $payload = self::b64($parts[0]);
        $sig = self::b64($parts[1]);
        $public = self::b64($pub);
        if ($payload===false || $sig===false || $public===false
            || strlen($sig)!==64 || strlen($public)!==32
            || !sodium_crypto_sign_verify_detached($sig, $payload, $public)) return $fallback;
        $c = json_decode($payload, true);
        $now=time();
        if (!is_array($c) || ($c['version']??null)!==1 || ($c['product']??'')!=='helixpanel'
            || ($c['installation_id']??'')!==$id
            || !is_string($c['license_id']??null) || !is_string($c['customer_id']??null)
            || !is_int($c['max_nodes']??null) || !is_int($c['max_users']??null)
            || !is_int($c['issued_at']??null) || !is_int($c['expires_at']??null)
            || $c['issued_at']>$now+300 || $c['expires_at']<=$now
            || $c['max_nodes']<1 || $c['max_users']<1) return $fallback;
        return [
            'active'=>true,'tier'=>'commercial', 'installation_id'=>$id,
            'max_nodes'=>$c['max_nodes'],'max_users'=>$c['max_users'],
            'license_id'=>$c['license_id'],'expires_at'=>$c['expires_at'],
        ];
    }
    private static function b64(string $s): string|false {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4-strlen($s)%4)%4),true);
    }
    public function nodes(): array {
        $rows = $this->query('SELECT id, name, region, status, enrolled_at, last_seen_at, agent_version, load_one, created_at FROM hp_nodes ORDER BY created_at DESC')->fetchAll();
        foreach ($rows as &$node) {
            if ($node['enrolled_at']!==null) {
                $last = $node['last_seen_at'] ? strtotime($node['last_seen_at'].' UTC') : 0;
                $node['status'] = $last >= time()-90 ? 'online' : 'offline';
            } else {
                $node['status']='pending_enrollment';
            }
        }
        unset($node);
        return $rows;
    }
    public function users(): array {
        return $this->query('SELECT id, name, quota_gb, expires_at, status, created_at FROM hp_users ORDER BY created_at DESC')->fetchAll();
    }
    public function handle(string $method, string $route, ?array $data=null): array {
        $lic=$this->license();
        if ($method==='GET') return match($route) {
            '/api/v1/license' => $lic,
            '/api/v1/nodes' => $this->nodes(),
            '/api/v1/users' => $this->users(),
            '/api/v1/summary' => $this->summary($lic),
            default => throw new RuntimeException('Unsupported API path'),
        };
        if ($method==='POST' && $route==='/api/v1/nodes') {
            $name=trim((string)($data['name']??''));
            $region=(string)($data['region']??'');
            if (!preg_match('/^[\pL\pN][\pL\pN._ -]{0,79}$/uD',$name) || !preg_match('/^[A-Z]{2}$/D',$region)) throw new RuntimeException('اطلاعات نود نامعتبر است.');
            if (count($this->nodes()) >= $lic['max_nodes']) throw new RuntimeException('سقف نودهای لایسنس تکمیل شده است.');
            $id=bin2hex(random_bytes(16));
            $this->query('INSERT INTO hp_nodes(id,name,region) VALUES(?,?,?)',[$id,$name,$region]);
            return ['id'=>$id,'name'=>$name,'region'=>$region,'status'=>'pending_enrollment'];
        }
        if ($method==='POST' && $route==='/api/v1/users') {
            $name=trim((string)($data['name']??''));
            $quota=$data['quota_gb']??null;
            $expiry=(string)($data['expires_at']??'');
            $until=strtotime($expiry);
            if (!preg_match('/^[\pL\pN][\pL\pN._ -]{0,79}$/uD',$name)
                || !is_int($quota) || $quota<1 || $quota>100000 || $until===false || $until<=time()) throw new RuntimeException('اطلاعات کاربر نامعتبر است.');
            if (count($this->users()) >= $lic['max_users']) throw new RuntimeException('سقف کاربران لایسنس تکمیل شده است.');
            $id=bin2hex(random_bytes(16));
            $this->query('INSERT INTO hp_users(id,name,quota_gb,expires_at) VALUES(?,?,?,?)',[$id,$name,$quota,gmdate('Y-m-d H:i:s',$until)]);
            return ['id'=>$id];
        }
        if ($method==='DELETE' && preg_match('~^/api/v1/(nodes|users)/([a-f0-9]{32})$~D',$route,$m)) {
            $table=$m[1]==='nodes'?'hp_nodes':'hp_users';
            $this->query("DELETE FROM $table WHERE id=?",[$m[2]]);
            return [];
        }
        if ($method==='POST' && preg_match('~^/api/v1/nodes/([a-f0-9]{32})/enrollment$~D',$route,$m)) {
            return $this->issueEnrollment($m[1]);
        }
        throw new RuntimeException('Unsupported API request');
    }
    private function summary(array $lic): array {
        $nodes=$this->nodes();
        $online=0;
        foreach($nodes as $node) if($node['status']==='online')$online++;
        return [
            'nodes'=>count($nodes), 'users'=>count($this->users()),
            'license'=>$lic, 'connected_nodes'=>$online,
            'traffic_bytes'=>null,
        ];
    }
    private function issueEnrollment(string $id): array {
        if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new RuntimeException('شناسه نود نامعتبر است.');
        if (count($this->nodes()) > $this->license()['max_nodes']) throw new RuntimeException('محدودیت لایسنس.');
        $token=bin2hex(random_bytes(32));
        $expiry=time()+900;
        $stmt=$this->query("UPDATE hp_nodes SET bootstrap_hash=?, bootstrap_expires_at=FROM_UNIXTIME(?), agent_hash=NULL, enrolled_at=NULL, last_seen_at=NULL, status='pending_enrollment' WHERE id=?",[
            hash('sha256',$token),$expiry,$id,
        ]);
        if ($stmt->rowCount()!==1) throw new RuntimeException('نود پیدا نشد.');
        return ['node_id'=>$id,'enrollment_token'=>$token,'expires_at'=>gmdate('c',$expiry)];
    }
    public function agentRequest(string $action, array $body): array {
        $id=$body['node_id']??null;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/D',$id)) throw new RuntimeException('Invalid node ID');
        $token=$body[$action==='enroll'?'enrollment_token':'agent_token']??null;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new RuntimeException('Invalid node credential');
        if ($action==='enroll') {
            $this->db->beginTransaction();
            try {
                $r=$this->query('SELECT bootstrap_hash, bootstrap_expires_at FROM hp_nodes WHERE id=? FOR UPDATE',[$id])->fetch();
                if (!$r || !$r['bootstrap_hash'] || !$r['bootstrap_expires_at']
                    || strtotime($r['bootstrap_expires_at'].' UTC')<=time()
                    || !hash_equals($r['bootstrap_hash'], hash('sha256',$token))) {
                    throw new RuntimeException('Invalid or expired enrollment');
                }
                $agent=bin2hex(random_bytes(32));
                $this->query('UPDATE hp_nodes SET bootstrap_hash=NULL, bootstrap_expires_at=NULL, agent_hash=?, enrolled_at=UTC_TIMESTAMP(), last_seen_at=UTC_TIMESTAMP(), status=? WHERE id=?',[
                    hash('sha256',$agent),'online',$id,
                ]);
                $this->db->commit();
                return ['agent_token'=>$agent,'interval_seconds'=>30,'protocol_version'=>1];
            } catch(Throwable $e) {$this->db->rollBack();throw $e;}
        }
        if ($action==='heartbeat') {
            $version=$body['agent_version']??null;
            $load=$body['load_1']??null;
            if (!is_string($version) || strlen($version)>64 || !str_starts_with($version,'v')
                || !is_numeric($load) || !is_finite((float)$load) || (float)$load<0 || (float)$load>1000000) throw new RuntimeException('Invalid heartbeat data');
            $r=$this->query('SELECT agent_hash FROM hp_nodes WHERE id=?',[$id])->fetch();
            if (!$r || !$r['agent_hash'] || !hash_equals($r['agent_hash'],hash('sha256',$token))) throw new RuntimeException('Invalid node token');
            $this->query('UPDATE hp_nodes SET last_seen_at=UTC_TIMESTAMP(), status=?, agent_version=?, load_one=? WHERE id=?',[
                'online',$version,(float)$load,$id,
            ]);
            return ['ok'=>true,'protocol_version'=>1,'desired_revision'=>0];
        }
        throw new RuntimeException('Unsupported node action');
    }
}

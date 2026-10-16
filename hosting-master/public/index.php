<?php
declare(strict_types=1);

/**
 * HelixPanel Master Gateway for cPanel / DirectAdmin shared hosting.
 * Only the UI and a narrow API relay run here; proprietary Go control plane
 * and licensed node orchestration remain on an independently hosted server.
 */
ini_set('display_errors', '0');
date_default_timezone_set('UTC');

function fail(int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}
function h(mixed $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function secureRequest(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
}
function configFile(): string {
    $root = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($root === false || $root === DIRECTORY_SEPARATOR) {
        fail(503, 'Document root is not configured.');
    }
    // Never infer a config location for a nested web folder: its parent may
    // itself be another PUBLIC document root (e.g. /public_html/master).
    if (basename($root) !== 'public_html') {
        fail(503, 'Use a dedicated document root ending in public_html.');
    }
    return dirname($root) . '/.helixpanel-master/config.php';
}

if (!secureRequest()) {
    fail(403, 'HTTPS is required. Activate SSL for the domain before logging in.');
}
if (PHP_VERSION_ID < 80200 || !extension_loaded('curl') || !extension_loaded('session')) {
    fail(503, 'HelixPanel Master requires PHP 8.2+, cURL and PHP sessions.');
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'sha256-CgHYjdx9T69rCMOb6XjrJFGWPA5lI/9YrtN62BTL+9k='; form-action 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'");
header('Strict-Transport-Security: max-age=31536000');
header('Cache-Control: no-store, private');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$configPath = configFile();
if (!is_file($configPath)) {
    // First-run browser wizard; setup.key lives outside public_html.
    header('Location: setup.php', true, 303);
    exit;
}
if (is_link($configPath)) {
    fail(503, 'Configuration must not be a symbolic link.');
}
$config = require $configPath;
if (!is_array($config)) {
    fail(503, 'Invalid master configuration.');
}
$mode=(string)($config['mode']??'gateway');
$standaloneStore=null;
if ($mode==='standalone') {
    define('HELIX_MASTER_INTERNAL',true);
    $source=dirname($configPath).'/store.php';
    if (!is_file($source)) fail(503,'Standalone master storage package is missing.');
    require $source;
    try { $standaloneStore=new HelixMasterStore($config); }
    catch(Throwable $e) { error_log('Master database error: '.get_class($e)); fail(503,'Master MySQL database unavailable.'); }
} else {
    $apiBase = rtrim((string)($config['upstream_api_base'] ?? ''), '/');
    $apiToken = (string)($config['upstream_api_token'] ?? '');
    $parsed = parse_url($apiBase);
    if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https'
        || empty($parsed['host']) || isset($parsed['user']) || isset($parsed['pass'])
        || isset($parsed['query']) || isset($parsed['fragment']) || isset($parsed['path'])
        || strlen($apiToken) < 32) fail(503,'Master HTTPS upstream configuration invalid.');
}
$passwordHash = (string)($config['admin_password_hash']??'');
if (!str_starts_with($passwordHash,'$2') && !str_starts_with($passwordHash,'$argon2'))
    fail(503,'Master password hash is missing.');

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('hp_master');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/', 'secure' => true,
    'httponly' => true, 'samesite' => 'Strict',
]);
if (!session_start()) {
    fail(503, 'Sessions are unavailable on this hosting provider.');
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

function csrf(): string { return h($_SESSION['csrf'] ?? ''); }
function validCsrf(): bool {
    return isset($_POST['csrf']) && is_string($_POST['csrf'])
        && hash_equals((string)($_SESSION['csrf'] ?? ''), $_POST['csrf']);
}
function redirectHome(): never {
    header('Location: ./', true, 303);
    exit;
}

/**
 * HTTPS-only API request with an allowlisted route constructed by the server.
 * The privileged token never appears in client JavaScript, HTML or URLs.
 */
function upstream(string $method, string $route, ?array $payload = null): array {
    global $apiBase, $apiToken, $standaloneStore;
    if ($standaloneStore instanceof HelixMasterStore) return $standaloneStore->handle($method,$route,$payload);
    if (!in_array($method, ['GET', 'POST', 'DELETE'], true)
        || !preg_match('~^/api/v1/(summary|license|nodes|users)(/[A-Za-z0-9_-]{1,100})?$~D', $route)) {
        throw new RuntimeException('درخواست API مجاز نیست.');
    }
    $url = $apiBase . $route;
    $curl = curl_init($url);
    if ($curl === false) { throw new RuntimeException('امکان ایجاد اتصال وجود ندارد.'); }
    $headers = ['Authorization: Bearer ' . $apiToken, 'Accept: application/json'];
    if ($payload !== null) { $headers[] = 'Content-Type: application/json'; }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,

        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_MAXREDIRS => 0,
    ];
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $opts[CURLOPT_PROTOCOLS_STR] = 'https';
    } else {
        $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    if ($payload !== null) { $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR); }
    curl_setopt_array($curl, $opts);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false || $status === 0) {
        error_log('HelixPanel Gateway: upstream transport failed: ' . substr($error, 0, 120));
        throw new RuntimeException('ارتباط امن با سرور مرکزی برقرار نشد.');
    }
    if ($status === 204) { return []; }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('سرور مرکزی درخواست را رد کرد (HTTP ' . $status . ').');
    }
    if (strlen($body) > 2_000_000) {
        throw new RuntimeException('پاسخ بیش از اندازه بزرگ است.');
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) { throw new RuntimeException('پاسخ سرور معتبر نیست.'); }
    return $decoded;
}

$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!validCsrf()) { fail(403, 'Invalid CSRF token. Refresh the page.'); }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'login' && empty($_SESSION['logged_in'])) {
        $until = (int)($_SESSION['locked_until'] ?? 0);
        if ($until > time()) { $err = 'تلاش‌های ناموفق زیاد است؛ چند دقیقه دیگر دوباره امتحان کنید.'; }
        elseif (password_verify((string)($_POST['password'] ?? ''), $passwordHash)) {
            session_regenerate_id(true);
            $_SESSION['logged_in'] = true;
            $_SESSION['last_seen'] = time();
            $_SESSION['failures'] = 0;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            redirectHome();
        } else {
            $_SESSION['failures'] = (int)($_SESSION['failures'] ?? 0) + 1;
            if ($_SESSION['failures'] >= 5) {
                $_SESSION['locked_until'] = time() + 300;
                $_SESSION['failures'] = 0;
            }
            $err = 'گذرواژه مدیریت صحیح نیست.';
        }
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: ./', true, 303);
        exit;
    }
}

if (empty($_SESSION['logged_in']) || (time() - (int)($_SESSION['last_seen'] ?? 0)) > 1800) {
    unset($_SESSION['logged_in']);
    http_response_code(200);
    ?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ورود | HelixPanel Master</title><link rel="icon" href="data:image/webp;base64,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" type="image/webp"><link rel="stylesheet" href="assets/master.css">
<style>
:root{color-scheme:dark;--bg:#080e1b;--surface:#101b2d;--panel:#121e31;--border:#243450;--text:#eef5ff;--muted:#8c9db8;--cyan:#48d5cf;--blue:#498cff;--danger:#ed6c88;--success:#3ed4a9}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.7 Tahoma,Arial,sans-serif}a{color:inherit;text-decoration:none}button,input{font:inherit}button{cursor:pointer}h1,h2,p{margin-top:0}
.login-body{min-height:100vh;display:grid;place-items:center;background:radial-gradient(ellipse 45% 40% at 75% 15%,#133655 0,transparent 100%),radial-gradient(ellipse 50% 45% at 10% 90%,#17244c 0,transparent 100%),var(--bg);padding:20px}
.login-card{width:min(430px,100%);padding:40px 34px;border-radius:24px;background:linear-gradient(145deg,#182843,#101827);border:1px solid #345070;box-shadow:0 20px 100px #0007}.login-card .brand-mark{margin-bottom:25px}.login-card h1{font-size:28px;margin-bottom:12px}
.brand-mark{width:53px;height:53px;border-radius:17px;background:linear-gradient(130deg,#4d8dfb,#4edbd0);color:#071124;display:grid;place-items:center;font:bold 32px Arial}.brand-mark span{color:#c8fff7}
.eyebrow{color:#54d1d7;font:700 11px Arial;letter-spacing:1.8px;margin:8px 0}
.muted{color:var(--muted)}.help{margin:18px 0 0;text-align:center;font-size:11px;color:var(--muted)}
label{display:block;color:#b5c6e2;font-size:12px;margin:0 0 7px}
input{display:block;border:1px solid #324762;background:#0b1525;color:#fff;border-radius:10px;padding:12px 13px;width:100%;outline:0;min-height:44px;direction:auto}
input:focus{border-color:var(--cyan);box-shadow:0 0 0 3px #48d5cf25}
.login-card form label{margin-top:26px}.login-card form button{width:100%;margin-top:18px}
.primary{border:0;border-radius:11px;background:linear-gradient(105deg,#3b95f6,#46c8ca);color:#041421;font-weight:800;padding:10px 19px;min-height:43px;white-space:nowrap}
.primary:hover{filter:brightness(1.1)}
.outline{border:1px solid #334760;color:#d1e8fc;background:transparent;border-radius:10px;padding:9px 15px}
.error,.success{padding:14px 18px;border:1px solid #8c3956;background:#501c3655;border-radius:11px;margin-bottom:16px}
.success{border-color:#26775e;background:#153f3555}
.dashboard-body{display:flex;min-height:100vh;background:radial-gradient(ellipse 35% 30% at 85% 0,#122e47 0,transparent 95%),var(--bg)}
.sidebar{width:250px;flex:none;background:#0e182a;border-left:1px solid var(--border);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;padding:25px 16px}
.brand{display:flex;gap:12px;align-items:center;padding:4px 4px 26px;border-bottom:1px solid var(--border);direction:ltr}.brand strong{display:block;letter-spacing:1px;font:800 19px Arial}.brand small{display:block;color:var(--muted);font:9px Arial;letter-spacing:1.2px}
.sidebar nav{display:flex;flex-direction:column;gap:7px;margin-top:24px}.sidebar nav a{padding:12px 14px;border-radius:10px;color:#abc0d9}.sidebar nav a:hover,.sidebar nav a:first-child{background:#1b3451;color:#62dfe3}
.sidebar-footer{margin-top:auto;color:var(--muted);border-top:1px solid var(--border);padding:18px 9px;font-size:12px}
.content{flex:1;min-width:0;max-width:1600px;padding:38px clamp(18px,4vw,55px)}
.topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:30px}.topbar h1{font-size:clamp(23px,3vw,34px);margin:8px 0}.topbar p{margin-bottom:0}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:23px}
.stat,.panel{background:linear-gradient(145deg,#17253a,#101a2b);border:1px solid var(--border);border-radius:17px;box-shadow:0 12px 30px #050b162a}
.stat{padding:22px;position:relative;overflow:hidden}.stat:after{content:"";position:absolute;inset:auto 0 0;height:2px;background:linear-gradient(90deg,transparent,#45a9e0,transparent)}
.stat span{color:#9aaec9}.stat strong{font-size:32px;display:block;margin:10px 0;font-weight:800}.stat small{color:var(--muted)}
.panel{padding:23px;margin-bottom:22px}.section-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:20px}.section-head h2{margin:0;font-size:19px}.section-head>span{font-size:12px;border:1px solid #294b5c;padding:5px 11px;border-radius:99px;color:#7ee1df}
.inline-form{display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:0 0 23px}.inline-form label{margin:0;flex:1;min-width:130px}.inline-form input{margin-top:6px}.inline-form button{margin-bottom:0}
.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;text-align:right;min-width:460px}th,td{border-bottom:1px solid #24364a;padding:13px 9px}th{color:#90abc2;font-weight:500;font-size:12px}td{color:#d6e3f4}.empty{text-align:center;color:var(--muted);padding:30px}
.danger{color:#f88bac;background:#5620354b;border:1px solid #7b3349;padding:6px 13px;border-radius:8px}
.license-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin-bottom:17px}
.license-grid>div{background:#091727;border:1px solid #203549;padding:13px;border-radius:10px;min-width:0}
.license-grid small{color:var(--muted);display:block;margin-bottom:6px}.license-grid strong{display:block;overflow-wrap:anywhere}
footer{font:11px Arial;color:#8098b0;text-align:center;padding:25px}
@media(max-width:1000px){.stats,.license-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.sidebar{width:205px}}
@media(max-width:700px){.dashboard-body{display:block}.sidebar{position:static;width:100%;height:auto;padding:13px 16px}.sidebar .brand{padding-bottom:12px}.sidebar nav{flex-direction:row;overflow:auto;margin-top:8px}.sidebar nav a{flex:none;padding:10px 13px}.sidebar-footer{display:none}.content{padding:24px 15px}.topbar{align-items:center}.topbar h1{font-size:22px}.stats{gap:10px}.stat{padding:14px}.stat strong{font-size:25px}.panel{padding:15px}.license-grid{gap:8px}}
@media(max-width:410px){.stats,.license-grid{grid-template-columns:1fr 1fr}.stat span{font-size:12px}.stat strong{font-size:22px}.topbar{flex-direction:column}}

/* Shared project identity: original HelixPanel emblem, no CSS-only H placeholder. */
.brand-mark{display:block;width:53px;height:53px;object-fit:contain;background:#eaf7ff!important;border-radius:15px;padding:4px;box-shadow:0 5px 20px #27b7e528}
</style>

</head><body class="login-body">
<main class="login-card">
<img class="brand-mark" src="data:image/webp;base64,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" width="53" height="53" alt="لوگوی HelixPanel">
<p class="eyebrow">HELIXPANEL · MASTER GATEWAY</p>
<h1>مرکز مدیریت هلیکس</h1>
<p class="muted">ورود ایمن به داشبورد مدیریتی مستقر روی هاست</p>
<?php if ($err !== ''): ?><p class="error" role="alert"><?= h($err) ?></p><?php endif; ?>
<form method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= csrf() ?>">
<input type="hidden" name="action" value="login">
<label for="password">رمز مدیریت</label>
<input id="password" type="password" name="password" minlength="12" required autofocus autocomplete="current-password">
<button class="primary" type="submit">ورود به مستر پنل</button>
</form>
<p class="help">HTTPS · نشست محافظت‌شده · اعتبارسنجی سمت سرور</p>
</main></body></html>
<?php
    exit;
}
$_SESSION['last_seen'] = time();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add-node') {
            $name = trim((string)($_POST['name'] ?? ''));
            $region = strtoupper(trim((string)($_POST['region'] ?? '')));
            if (strlen($name) < 2 || strlen($name) > 80 || !preg_match('/^[A-Z]{2}$/', $region)) {
                throw new RuntimeException('نام نود یا کد کشور نامعتبر است.');
            }
            upstream('POST', '/api/v1/nodes', ['name' => $name, 'region' => $region]);
        } elseif ($action === 'add-user') {
            $name = trim((string)($_POST['name'] ?? ''));
            $quota = filter_var($_POST['quota_gb'] ?? null, FILTER_VALIDATE_INT);
            $end = (string)($_POST['expires_at'] ?? '');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $end, new DateTimeZone('UTC'));
            if (strlen($name) < 2 || strlen($name) > 80 || $quota === false || $quota < 1
                || $quota > 100_000 || $date === false || $date->format('Y-m-d') !== $end) {
                throw new RuntimeException('اطلاعات مشترک صحیح نیست.');
            }
            upstream('POST', '/api/v1/users', [
                'name' => $name, 'quota_gb' => $quota,
                'expires_at' => $date->setTime(23, 59, 59)->format('Y-m-d\TH:i:s\Z'),
            ]);
        } elseif ($action === 'enroll-node') {
            $id=(string)($_POST['id']??'');
            if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new RuntimeException('شناسه نود نامعتبر است.');
            if (!$standaloneStore) throw new RuntimeException('ساخت کد اتصال فقط در حالت مستر مستقل فعال است.');
            $_SESSION['enrollment']=upstream('POST','/api/v1/nodes/'.$id.'/enrollment');
        } elseif ($action === 'delete-node' || $action === 'delete-user') {
            $id = (string)($_POST['id'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/D', $id)) {
                throw new RuntimeException('شناسه معتبر نیست.');
            }
            upstream('DELETE', '/api/v1/' . ($action === 'delete-node' ? 'nodes/' : 'users/') . $id);
        } elseif ($action !== 'login') {
            throw new RuntimeException('عملیات پشتیبانی نمی‌شود.');
        }
        $_SESSION['notice'] = 'درخواست با موفقیت ثبت شد.';
        redirectHome();
    } catch (Throwable $e) {
        $err = $e instanceof RuntimeException ? $e->getMessage() : 'خطا در پردازش درخواست.';
    }
}

$nodes = []; $users = []; $summary = []; $license = [];
try {
    $summary = upstream('GET', '/api/v1/summary');
    $nodes = upstream('GET', '/api/v1/nodes');
    $users = upstream('GET', '/api/v1/users');
    $license = upstream('GET', '/api/v1/license');
} catch (Throwable $e) {
    $err = $e instanceof RuntimeException ? $e->getMessage() : 'خطای غیرمنتظره.';
}
$notice = (string)($_SESSION['notice'] ?? '');
unset($_SESSION['notice']);
$enrollment = $_SESSION['enrollment'] ?? null;
unset($_SESSION['enrollment']);
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HelixPanel Master | مرکز مدیریت</title><link rel="icon" href="data:image/webp;base64,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" type="image/webp">
<link rel="stylesheet" href="assets/master.css">
<style>
:root{color-scheme:dark;--bg:#080e1b;--surface:#101b2d;--panel:#121e31;--border:#243450;--text:#eef5ff;--muted:#8c9db8;--cyan:#48d5cf;--blue:#498cff;--danger:#ed6c88;--success:#3ed4a9}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.7 Tahoma,Arial,sans-serif}a{color:inherit;text-decoration:none}button,input{font:inherit}button{cursor:pointer}h1,h2,p{margin-top:0}
.login-body{min-height:100vh;display:grid;place-items:center;background:radial-gradient(ellipse 45% 40% at 75% 15%,#133655 0,transparent 100%),radial-gradient(ellipse 50% 45% at 10% 90%,#17244c 0,transparent 100%),var(--bg);padding:20px}
.login-card{width:min(430px,100%);padding:40px 34px;border-radius:24px;background:linear-gradient(145deg,#182843,#101827);border:1px solid #345070;box-shadow:0 20px 100px #0007}.login-card .brand-mark{margin-bottom:25px}.login-card h1{font-size:28px;margin-bottom:12px}
.brand-mark{width:53px;height:53px;border-radius:17px;background:linear-gradient(130deg,#4d8dfb,#4edbd0);color:#071124;display:grid;place-items:center;font:bold 32px Arial}.brand-mark span{color:#c8fff7}
.eyebrow{color:#54d1d7;font:700 11px Arial;letter-spacing:1.8px;margin:8px 0}
.muted{color:var(--muted)}.help{margin:18px 0 0;text-align:center;font-size:11px;color:var(--muted)}
label{display:block;color:#b5c6e2;font-size:12px;margin:0 0 7px}
input{display:block;border:1px solid #324762;background:#0b1525;color:#fff;border-radius:10px;padding:12px 13px;width:100%;outline:0;min-height:44px;direction:auto}
input:focus{border-color:var(--cyan);box-shadow:0 0 0 3px #48d5cf25}
.login-card form label{margin-top:26px}.login-card form button{width:100%;margin-top:18px}
.primary{border:0;border-radius:11px;background:linear-gradient(105deg,#3b95f6,#46c8ca);color:#041421;font-weight:800;padding:10px 19px;min-height:43px;white-space:nowrap}
.primary:hover{filter:brightness(1.1)}
.outline{border:1px solid #334760;color:#d1e8fc;background:transparent;border-radius:10px;padding:9px 15px}
.error,.success{padding:14px 18px;border:1px solid #8c3956;background:#501c3655;border-radius:11px;margin-bottom:16px}
.success{border-color:#26775e;background:#153f3555}
.dashboard-body{display:flex;min-height:100vh;background:radial-gradient(ellipse 35% 30% at 85% 0,#122e47 0,transparent 95%),var(--bg)}
.sidebar{width:250px;flex:none;background:#0e182a;border-left:1px solid var(--border);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;padding:25px 16px}
.brand{display:flex;gap:12px;align-items:center;padding:4px 4px 26px;border-bottom:1px solid var(--border);direction:ltr}.brand strong{display:block;letter-spacing:1px;font:800 19px Arial}.brand small{display:block;color:var(--muted);font:9px Arial;letter-spacing:1.2px}
.sidebar nav{display:flex;flex-direction:column;gap:7px;margin-top:24px}.sidebar nav a{padding:12px 14px;border-radius:10px;color:#abc0d9}.sidebar nav a:hover,.sidebar nav a:first-child{background:#1b3451;color:#62dfe3}
.sidebar-footer{margin-top:auto;color:var(--muted);border-top:1px solid var(--border);padding:18px 9px;font-size:12px}
.content{flex:1;min-width:0;max-width:1600px;padding:38px clamp(18px,4vw,55px)}
.topbar{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:30px}.topbar h1{font-size:clamp(23px,3vw,34px);margin:8px 0}.topbar p{margin-bottom:0}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:23px}
.stat,.panel{background:linear-gradient(145deg,#17253a,#101a2b);border:1px solid var(--border);border-radius:17px;box-shadow:0 12px 30px #050b162a}
.stat{padding:22px;position:relative;overflow:hidden}.stat:after{content:"";position:absolute;inset:auto 0 0;height:2px;background:linear-gradient(90deg,transparent,#45a9e0,transparent)}
.stat span{color:#9aaec9}.stat strong{font-size:32px;display:block;margin:10px 0;font-weight:800}.stat small{color:var(--muted)}
.panel{padding:23px;margin-bottom:22px}.section-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:20px}.section-head h2{margin:0;font-size:19px}.section-head>span{font-size:12px;border:1px solid #294b5c;padding:5px 11px;border-radius:99px;color:#7ee1df}
.inline-form{display:flex;gap:12px;align-items:end;flex-wrap:wrap;margin:0 0 23px}.inline-form label{margin:0;flex:1;min-width:130px}.inline-form input{margin-top:6px}.inline-form button{margin-bottom:0}
.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;text-align:right;min-width:460px}th,td{border-bottom:1px solid #24364a;padding:13px 9px}th{color:#90abc2;font-weight:500;font-size:12px}td{color:#d6e3f4}.empty{text-align:center;color:var(--muted);padding:30px}
.danger{color:#f88bac;background:#5620354b;border:1px solid #7b3349;padding:6px 13px;border-radius:8px}
.license-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:13px;margin-bottom:17px}
.license-grid>div{background:#091727;border:1px solid #203549;padding:13px;border-radius:10px;min-width:0}
.license-grid small{color:var(--muted);display:block;margin-bottom:6px}.license-grid strong{display:block;overflow-wrap:anywhere}
footer{font:11px Arial;color:#8098b0;text-align:center;padding:25px}
@media(max-width:1000px){.stats,.license-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.sidebar{width:205px}}
@media(max-width:700px){.dashboard-body{display:block}.sidebar{position:static;width:100%;height:auto;padding:13px 16px}.sidebar .brand{padding-bottom:12px}.sidebar nav{flex-direction:row;overflow:auto;margin-top:8px}.sidebar nav a{flex:none;padding:10px 13px}.sidebar-footer{display:none}.content{padding:24px 15px}.topbar{align-items:center}.topbar h1{font-size:22px}.stats{gap:10px}.stat{padding:14px}.stat strong{font-size:25px}.panel{padding:15px}.license-grid{gap:8px}}
@media(max-width:410px){.stats,.license-grid{grid-template-columns:1fr 1fr}.stat span{font-size:12px}.stat strong{font-size:22px}.topbar{flex-direction:column}}

/* Shared project identity: original HelixPanel emblem, no CSS-only H placeholder. */
.brand-mark{display:block;width:53px;height:53px;object-fit:contain;background:#eaf7ff!important;border-radius:15px;padding:4px;box-shadow:0 5px 20px #27b7e528}
</style>
</head>
<body class="dashboard-body">
<aside class="sidebar">
<div class="brand"><img class="brand-mark" src="data:image/webp;base64,UklGRsgJAABXRUJQVlA4WAoAAAAQAAAAPwAAPQAAQUxQSJsDAAABoLRtmyFZXtL7xdcY27ZtLO1pjY3W2DO7YxursW17VrZt2+rKL7530V0RkX0iYgJQOKigzqCCekMAIOVEAUgdosB2R60IKSQKrHvEKpBiosD6l//Hl1YQKaLAqtN+5sfrSSikwArjfyUbPA4tBYJgiQu+Joc4Bi1FQkAY+JQ0t9hXIChwxFukeRXnFAkKHPgCaU4a+7NEgT0fI83JijMLiALb30FaJIf15iiwyXWRMZLDZuUJsO6c/+mRI+YJVpn8J2kcseJMacsQtF36E2lsauxPEsFZX5PGphUXAJokstTDpDlTBpICZpHmbO7++12bQSRBMZFDzlRjb0rATrTI3D8ugUgTgb4ejeUU3W7MdCOvDkGatb7PmNOX1sEs0hu8FCHhvbzetM4S9PjXOgg19Kd1FGHFc9FSw+Bo8JuhNfSXi3zjIzpJ4+OQGvrKkf83ONx4J8Ki0bTiWLQUq2xwFETfFVqMPKE+46MIKOT+23lLBKnJrdqpXPTPNwWCBqmj4kVQJLyfRmc1fn0ACKqq7aFEg5dBJeW9DDr5901HrisYuTOv4n0SBDXQjeQfr9w8Zexp42be/DE9I/LOtiCohXQzFjfe3wJBXSQ9NoYqqyqLeWPQgtFQ3jhBdFQ4336VXqLiZOS0FTIOnkwrMzUntH9UhvznLxatOCWjBQf8457jMcboLFxxapri0H/oLGxVLDMLLQkBe/7LyOxfv/jg7U9/GCIZraaAdX9gZK7x/KWX0KVX3OyYBe+SHvPmJcljrFigHwEjLt75ILMrzk9Q9NBY4jRpFZGgCqD7Y/dyQZ72Mn1owYiiLeig5cxrFrDW3/QipzUDVDvz5jdT7ENnkd4kdOTNTulkHOaWM1DTvJRdopPO356mp51W06xmgqU+9UiSkcnG/tECxRg2hmUa+2qanSBh2TfZWJQQsMnHtAK9Nc1NQcD6j7JAf1pn3pwkBOC8v90zPKMjK86BpkCBDxmTonF3aA0NHp8hsniaG7kgBBSLxueXC5KG9o9SjPziDEBKxUjeujIExcz5x5SVIUhVdDZzI5/ZDxCUikZeuzmgyOgYyY385BRFEGS1fTjMjXx8L0AFZYz8ZdxygCJ7JDfyveMFGpCrOMyNMdIu3whQQYnWj+IQ+eMlywGKfEUPh4x8aA9ABUUDxpP/LlwbUJQM2PBn8tUuQANKCy6ctDWggrKCHRce3w4JqDkIigsAKOpVDagzqArKAgBWUDggBgYAABAcAJ0BKkAAPgA+nUCZSSWjoiEqDFiwE4lsAKqVrtZeIWYHd29EPvXeg1m3T8wHm0eg//S+jp/gOsl9ADy4f27+C3+4/+H9qvaUzS/sJ/yfhr4zfb8ADt+IlGT47mTg4p+Al8PTwb/AenH/Vf0D2Hv9fy+/Pn/e/xfwFfyj+l/7n+6dqP0N/1ZZ0Ygyvf75/Xx85pm+RPed/9wlTO4n+/BWBwtYahSGO2NKwwvOCwMjSmcz9msOB1oFbjDSfd89z2EPVG6nU5XL3Gx6eHdhK4uAxrkE//kf0Ve59+exR9ivack3v+g7/akAAP78c7cDUJDaLz5kNPPM1+EXn9NFTXt//uGQtrNjnjV9MSpCd0ppie0Hf+D3c+Tr4j8k3eTxWkqvmh53VFWcwF0iXrMz5FC57HNZBcvXp9o1ET3+druWA1jn9y9PJkK3i+h+4e0nlXbdDnhBNlYxtoL7qCurnrAaB8ohQYsFA4xZEc4FKMDY8CftG9Hr/uyp4MpFRmnk6nvRYQpsCR4FY7j3r1YsFnr2/7kh7nRDLX+6A+QDQFp2LnEH1wqAc0CKY3oRmFe7l8WcG/5zgamO4/GrfY/DPM7qFd0gDuJHaWOjJCb9INk0hO3BRFKxRUNi/yxQZ91b7QNJ03ZDK3k1jYhwmG9aoUpfWDhqqh6QZ+qd5abt2wGVtFK0vUAYeQye5RyD2UqgMFvU2Q8CIAREdILRTZZgrGi9eAKFteOKhYEC6ucwccaNxhBOd36aDFnFdyEyrr7AVjuCkyADpt8xzxAmtvBzPniWs23OwwXm6OHmfZIznSB/nuT/UrLr17BuO0GRKXNaeIe4L1XfCzRuuYNNWNtWDE8yyTx56/o8lCYtiRJlfuGKKK4tuLNUHfzl9Pp1e8+STDneB+NJnX4PQaIj3cReR1s/8xQMVMFMKyOidEEZzDpdLx1st9mCFLSUOMbEzO8v4K9udd6D27m510AkrNjR/7tNxI/lBIYY+Ir74QGATJ9Tmiga7L2C7fwXZT55V1T4Yt4PiIbOfA3Qh03XpX2J0bKht6whu5rSpildYuuYTWEFTzOXAXlgWHhRxkQ9cOqGN6L20a7/szLKCgyj0Usr3Kxnwknf3eN/xGgPu1QTOasSlpVIjuCbcGTISQjIkgqL+M3tO8StqZJjgXT9WCfoSGvjSH15IuUrB6MmU/vOH4L8lvubjXSvPoS0nQMIHGJOeBsqYycExJZOEFRf3QXzX/aQgIT/nHPYZ/O26gfN2Zi93rP/Zo+v2pcuSGkWlVaz0ZbQJzTgfcaoJt5dvXuDV+UB2OtsoCazmhGy+3lUrkiaMLMPM4HxbS48lwfvUUhtR+cu8/ihYrFZ7/YeHPqjL/FCa9WGOenzcon0XsWuGoyOgfXkDxdwtLMo/t1YlV6BHXmk+YRjDLiEGubym0oP5Y7gox0fAyMFV3jdZd65/22eRRU6vzzUSCTmTRkDOaCod13jpkh/YbeSysUseT8oJ16pPmvnVKDHTa++mK+ksvcF0Kfvmyu4ncG8j9FMhytMbCR5Uzn6aRka6BitdGYVVSuiCo3WcGS5ykf8xrwnUzKeLeOkkYz+4qAJDYPaou2LM163og10ds8mhCobEIh5Lz5LbZMlfT9p/zOP4c5Aen6TIpFB32LPvmxf6RZz1vqDIGru91ySSQ3ezLMzrsbV3eJqvVD0kWTz85uAlQIJiyZqKnPL8Mb9j3fOD1rT1NBRgF/TYIt1s6WfXRJcrHpY7cuXlRonXy4526WEnaAmf/c8eixW6jwVIO1aO7iTZPZC1d3E69qKGf78uccP9/g2AdFOt3Ydh9qq3BPepP1tjqQdtHiMSrggg0eb+Fs0a92ztTnYPKvL3PdL2ZcC4zrz/aONk+n571gM3l+hJB1hxS8tuZMERpyHNo4wUVtXuCoxhf33rfNkvS1uKod3jfhXOC2vTw6akxn6c3iKMrCirCBf+c9+QyRu/mXnwE5C1HfV3oqWnnwyc/yso5mLHeZTZPJfSCmkE9/yCTj/lAgdZ21BD2OYYX99nOz3r00pk6Zj3eaFIAAAAA==" width="53" height="53" alt="لوگوی HelixPanel"><div><strong>HELIXPANEL</strong><small>MASTER GATEWAY</small></div></div>
<nav aria-label="فهرست">
<a href="#overview">◈ نمای کلی</a><a href="#nodes">▣ مدیریت نودها</a>
<a href="#users">♧ مشترکین</a><a href="#license">◇ لایسنس</a>
</nav>
<div class="sidebar-footer">نسخه آزمایشی · PHP Gateway<br><small>cPanel / DirectAdmin</small></div>
</aside>
<main class="content">
<header class="topbar"><div><div class="eyebrow">CONTROL CENTER / MASTER</div><h1>مرکز مدیریت زیرساخت</h1><p class="muted">مستر مدیریتی مستقل؛ داده و ترافیک VPN فقط روی نودها</p></div>
<form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="logout"><button class="outline" type="submit">خروج امن</button></form></header>
<?php if ($err !== ''): ?><div class="error" role="alert"><?= h($err) ?></div><?php endif; ?>
<?php if ($notice !== ''): ?><div class="success" role="status"><?= h($notice) ?></div><?php endif; ?>
<?php if (is_array($enrollment)): ?>
<div class="success" role="status">
<strong>کد اتصال نود (فقط همین بار نمایش داده می‌شود):</strong>
<p>Node ID: <code><?= h($enrollment['node_id']??'') ?></code></p>
<p>Enrollment Token: <code dir="ltr"><?= h($enrollment['enrollment_token']??'') ?></code></p>
<small>اعتبار ۱۵ دقیقه. صدور مجدد، توکن قبلی نود را باطل می‌کند. برای نصب Node Agent به مستندات مراجعه کنید.</small>
</div>
<?php endif; ?>

<section id="overview" class="stats">
<article class="stat"><span>نودهای ثبت‌شده</span><strong><?= h($summary['nodes'] ?? count($nodes)) ?></strong><small>اطلاعات واقعی API</small></article>
<article class="stat"><span>مشترکین</span><strong><?= h($summary['users'] ?? count($users)) ?></strong><small>بدون آمار ساختگی</small></article>
<article class="stat"><span>مجوز</span><strong><?= !empty($license['active']) ? 'تجاری' : 'رایگان' ?></strong><small><?= !empty($license['active']) ? 'فعال' : '۱ نود · ۲۵ کاربر' ?></small></article>
<article class="stat"><span>نودهای متصل</span><strong><?= h($summary['connected_nodes'] ?? 0) ?></strong><small>Agent هنوز پیاده‌سازی نشده</small></article>
</section>
<section class="panel" id="nodes"><div class="section-head"><h2>مدیریت نودها</h2><span><?= count($nodes) ?> نود</span></div>
<form method="post" class="inline-form">
<input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="add-node">
<label>نام نود <input name="name" maxlength="80" required placeholder="Frankfurt-1"></label>
<label>کشور <input name="region" maxlength="2" minlength="2" required placeholder="DE" value="DE"></label>
<button class="primary" type="submit">افزودن نود</button>
</form>
<div class="table-wrap"><table><thead><tr><th>نام</th><th>کشور</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
<?php foreach ($nodes as $node): if (!is_array($node)) continue; ?>
<tr><td><?= h($node['name'] ?? '') ?></td><td><?= h($node['region'] ?? '') ?></td><td><?= h(match($node['status'] ?? '') {'online'=>'آنلاین','offline'=>'آفلاین', default=>'در انتظار اتصال'}) ?></td><td>
<?php if ($standaloneStore): ?><form method="post" class="node-enroll"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="enroll-node"><input type="hidden" name="id" value="<?= h($node['id'] ?? '') ?>"><button class="outline" type="submit">صدور کد اتصال</button></form><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="delete-node"><input type="hidden" name="id" value="<?= h($node['id'] ?? '') ?>"><button class="danger" type="submit">حذف</button></form></td></tr>
<?php endforeach; ?>
<?php if (!$nodes): ?><tr><td colspan="4" class="empty">هنوز نودی ثبت نشده است.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="panel" id="users"><div class="section-head"><h2>مدیریت مشترکین</h2><span><?= count($users) ?> مشترک</span></div>
<form method="post" class="inline-form">
<input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="add-user">
<label>نام <input name="name" maxlength="80" required placeholder="customer-001"></label>
<label>حجم (GB) <input type="number" name="quota_gb" value="20" min="1" max="100000" required></label>
<label>انقضا <input type="date" name="expires_at" required value="<?= h(gmdate('Y-m-d', time()+30*86400)) ?>"></label>
<button class="primary" type="submit">ایجاد مشترک</button>
</form>
<div class="table-wrap"><table><thead><tr><th>نام</th><th>سهمیه</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>
<?php foreach ($users as $user): if (!is_array($user)) continue; ?>
<tr><td><?= h($user['name'] ?? '') ?></td><td><?= h($user['quota_gb'] ?? '—') ?> GB</td><td><?= h($user['status'] ?? 'ثبت شده') ?></td><td>
<form method="post"><input type="hidden" name="csrf" value="<?= csrf() ?>"><input type="hidden" name="action" value="delete-user"><input type="hidden" name="id" value="<?= h($user['id'] ?? '') ?>"><button class="danger" type="submit">حذف</button></form></td></tr>
<?php endforeach; ?>
<?php if (!$users): ?><tr><td colspan="4" class="empty">هنوز مشترکی ثبت نشده است.</td></tr><?php endif; ?>
</tbody></table></div></section>
<section class="panel" id="license"><div class="section-head"><h2>وضعیت لایسنس</h2><span><?= !empty($license['active']) ? 'فعال' : 'Community رایگان' ?></span></div>
<div class="license-grid">
<div><small>شناسه لایسنس</small><strong><?= h($license['license_id'] ?? '—') ?></strong></div>
<div><small>سقف کاربران</small><strong><?= h($license['max_users'] ?? '—') ?></strong></div>
<div><small>سقف نودها</small><strong><?= h($license['max_nodes'] ?? '—') ?></strong></div>
<div><small>تاریخ انقضا</small><strong><?= !empty($license['expires_at']) ? h(gmdate('Y-m-d', (int)$license['expires_at'])) : '—' ?></strong></div>
</div>
<p class="muted">صدور و تمدید لایسنس در هسته خصوصی انجام می‌شود؛ هیچ کلید خصوصی روی هاست نصب نمی‌شود.</p></section>
<footer>HelixPanel Master Gateway · Developer Preview · Hosted PHP 8.2+</footer>
</main></body></html>

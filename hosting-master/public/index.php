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
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; img-src 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Strict-Transport-Security: max-age=31536000');
header('Cache-Control: no-store, private');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

$configPath = configFile();
if (!is_file($configPath)) {
    fail(503, 'HelixPanel Master is not configured. See hosting-master/README.fa.md.');
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
<title>ورود | HelixPanel Master</title><link rel="stylesheet" href="assets/master.css">
</head><body class="login-body">
<main class="login-card">
<div class="brand-mark">H<span>.</span></div>
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
<title>HelixPanel Master | مرکز مدیریت</title>
<link rel="stylesheet" href="assets/master.css"></head>
<body class="dashboard-body">
<aside class="sidebar">
<div class="brand"><div class="brand-mark">H<span>.</span></div><div><strong>HELIXPANEL</strong><small>MASTER GATEWAY</small></div></div>
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
<article class="stat"><span>مجوز</span><strong><?= !empty($license['active']) ? 'تجاری' : 'پایه' ?></strong><small><?= !empty($license['active']) ? 'فعال' : 'محدود / آزمایشی' ?></small></article>
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
<section class="panel" id="license"><div class="section-head"><h2>وضعیت لایسنس</h2><span><?= !empty($license['active']) ? 'فعال' : 'نسخه پایه' ?></span></div>
<div class="license-grid">
<div><small>شناسه لایسنس</small><strong><?= h($license['license_id'] ?? '—') ?></strong></div>
<div><small>سقف کاربران</small><strong><?= h($license['max_users'] ?? '—') ?></strong></div>
<div><small>سقف نودها</small><strong><?= h($license['max_nodes'] ?? '—') ?></strong></div>
<div><small>تاریخ انقضا</small><strong><?= !empty($license['expires_at']) ? h(gmdate('Y-m-d', (int)$license['expires_at'])) : '—' ?></strong></div>
</div>
<p class="muted">صدور و تمدید لایسنس در هسته خصوصی انجام می‌شود؛ هیچ کلید خصوصی روی هاست نصب نمی‌شود.</p></section>
<footer>HelixPanel Master Gateway · Developer Preview · Hosted PHP 8.2+</footer>
</main></body></html>

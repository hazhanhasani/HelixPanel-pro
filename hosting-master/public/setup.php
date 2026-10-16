<?php
declare(strict_types=1);
/**
 * HelixPanel shared-hosting web setup. The one-time setup challenge is stored
 * OUTSIDE public_html, so a random unauthenticated visitor cannot claim the
 * first administrator account merely by reaching this installer.
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');
umask(0077);
function hpEsc(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function hpHalt(int $status,string $message): never {
 http_response_code($status);
 header('Content-Type: text/plain; charset=utf-8');
 echo $message;
 exit;
}
function hpSecure(): bool {
 return ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
  || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
}
if (!hpSecure()) hpHalt(403,'HTTPS is required before running HelixPanel setup.');
if (PHP_VERSION_ID < 80200 || !extension_loaded('pdo_mysql') || !extension_loaded('session')) {
 hpHalt(503,'PHP 8.2+, PDO MySQL and PHP sessions are required.');
}
$root=realpath((string)($_SERVER['DOCUMENT_ROOT']??''));
if ($root===false || basename($root)!=='public_html' || realpath(__DIR__)!==$root) {
 hpHalt(503,'Install the files directly inside a dedicated public_html document root.');
}
$private=dirname($root).'/.helixpanel-master';
$configFile=$private.'/config.php';
if (is_file($configFile)) {
 header('Location: ./',true,303);exit;
}
if ((is_link($private) || (!is_dir($private) && !@mkdir($private,0700,true)))) {
 hpHalt(503,'Create a .helixpanel-master directory beside public_html, outside the web root.');
}
if (!is_dir($private) || is_link($private)) hpHalt(503,'Invalid private configuration directory.');
@chmod($private,0700);
$keyFile=$private.'/setup.key';
if (is_link($keyFile)) hpHalt(503,'Unsafe setup challenge path.');
if (!is_file($keyFile)) {
 $key=bin2hex(random_bytes(32));
 $f=@fopen($keyFile,'x');
 if ($f===false && !is_file($keyFile)) hpHalt(503,'Cannot create setup.key outside public_html.');
 if ($f!==false) {
   @chmod($keyFile,0600);
   if (fwrite($f,$key."\n")!==strlen($key)+1) {fclose($f);@unlink($keyFile);hpHalt(503,'Cannot save setup challenge.');}
   fflush($f);fclose($f);
 }
}
$storedKey=@file_get_contents($keyFile);
if (!is_string($storedKey) || !preg_match('/^[a-f0-9]{64}\s*$/D',$storedKey)) {
 hpHalt(503,'Invalid setup.key; inspect file permissions in File Manager.');
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self' 'sha256-TC82JHoTZLhNMICZLxs15Yoj/mROZPDjAs3SC6zbeYw='; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Strict-Transport-Security: max-age=31536000');
header('Cache-Control: no-store, private');
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_name('hp_setup');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);
if (!session_start()) hpHalt(503,'PHP sessions are unavailable.');
$_SESSION['setup_csrf']??=bin2hex(random_bytes(32));

$errors=[];
$setupDone=false;
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
 $tooMany=((int)($_SESSION['locked_until']??0))>time();
 if ($tooMany) { $errors[]='تلاش‌های ناموفق زیاد بود. کمی بعد دوباره امتحان کنید.'; }
 elseif (!isset($_POST['csrf']) || !is_string($_POST['csrf']) ||
  !hash_equals((string)$_SESSION['setup_csrf'],$_POST['csrf'])) {
   $errors[]='نشست نصب منقضی شده است؛ صفحه را دوباره باز کنید.';
 } else {
   $attempt=(string)($_POST['setup_key']??'');
   if (!hash_equals(trim($storedKey),$attempt)) {
     $_SESSION['bad_setup']=(int)($_SESSION['bad_setup']??0)+1;
     if ($_SESSION['bad_setup']>=5) {$_SESSION['locked_until']=time()+900;$_SESSION['bad_setup']=0;}
     $errors[]='کد راه‌اندازی اشتباه است؛ آن را از فایل خصوصی setup.key بخوانید.';
   } else {
     $_SESSION['bad_setup']=0;
     $host=trim((string)($_POST['db_host']??'localhost'));
     $port=(int)($_POST['db_port']??3306);
     $name=trim((string)($_POST['db_name']??''));
     $user=trim((string)($_POST['db_user']??''));
     $pass=(string)($_POST['db_password']??'');
     $adminPass=(string)($_POST['admin_password']??'');
     $confirm=(string)($_POST['confirm_password']??'');
     if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}$/D',$host) ||
       $port<1 || $port>65535 ||
       !preg_match('/^[A-Za-z0-9_]{1,64}$/D',$name) ||
       !preg_match('/^[A-Za-z0-9_.@-]{1,80}$/D',$user) ||
       $pass==='' || strlen($pass)>512) {
        $errors[]='مشخصات MySQL نامعتبر است.';
     }
     if (strlen($adminPass)<14 || strlen($adminPass)>256 ||
       !hash_equals($adminPass,$confirm)) $errors[]='رمز مدیر باید حداقل ۱۴ کاراکتر باشد و تکرارش یکسان باشد.';
     if (!$errors) {
       try {
         $dsn="mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
         $db=new PDO($dsn,$user,$pass,[
           PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
           PDO::ATTR_EMULATE_PREPARES=>false,
           PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
           PDO::ATTR_TIMEOUT=>8,
         ]);
         $db->exec("SET time_zone = '+00:00'");
         $queries=[
           "CREATE TABLE IF NOT EXISTS hp_master_meta (meta_key VARCHAR(64) PRIMARY KEY, meta_value VARCHAR(512) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
           "CREATE TABLE IF NOT EXISTS hp_nodes (id CHAR(32) PRIMARY KEY, name VARCHAR(80) NOT NULL, region CHAR(2) NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'pending_enrollment', bootstrap_hash CHAR(64) NULL, bootstrap_expires_at DATETIME NULL, agent_hash CHAR(64) NULL, enrolled_at DATETIME NULL, last_seen_at DATETIME NULL, agent_version VARCHAR(64) NULL, load_one DOUBLE NOT NULL DEFAULT 0, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY ux_node_name(name), KEY ix_node_online(last_seen_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
           "CREATE TABLE IF NOT EXISTS hp_users (id CHAR(32) PRIMARY KEY, name VARCHAR(80) NOT NULL, quota_gb INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'pending_provisioning', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY ux_user_name(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
         ];
         foreach ($queries as $query) $db->exec($query);
         // Use the same stable installation ID for free and commercial tiers.
         $q=$db->prepare('INSERT IGNORE INTO hp_master_meta(meta_key,meta_value) VALUES(?,?)');
         $q->execute(['installation_id',bin2hex(random_bytes(16))]);
         $src=__DIR__.'/setup-store.php';
         $dst=$private.'/store.php';
         if (!is_file($dst)) {
           if (!is_file($src) || is_link($src)) throw new RuntimeException('The setup-store.php package file is missing.');
           $tmp=$private.'/.store-'.bin2hex(random_bytes(6)).'.php';
           if (!copy($src,$tmp) || !chmod($tmp,0600) || !rename($tmp,$dst)) {
             @unlink($tmp);throw new RuntimeException('Could not place the private MySQL master code.');
           }
         }
         if (!is_file($dst) || is_link($dst)) throw new RuntimeException('Private master source is unsafe.');
         $cfg=[
           'mode'=>'standalone','db_dsn'=>$dsn,'db_user'=>$user,'db_password'=>$pass,
           'admin_password_hash'=>password_hash($adminPass,PASSWORD_DEFAULT),
           'license_key'=>'','license_public_key'=>'UgMjnCA3zJnoH7Sn4CVZ-GucoqitVOdmrP6t03XlH9o',
         ];
         $contents="<?php\ndeclare(strict_types=1);\nreturn ".var_export($cfg,true).";\n";
         $out=@fopen($configFile,'x');
         if ($out===false) throw new RuntimeException('Configuration file already exists or cannot be created.');
         @chmod($configFile,0600);
         $written=fwrite($out,$contents);
         fflush($out);fclose($out);
         if ($written!==strlen($contents)) {
           @unlink($configFile);throw new RuntimeException('Unable to write complete secure config.');
         }
         @unlink($keyFile);
         @unlink($src);
         session_regenerate_id(true);
         $setupDone=true;
         header('Location: ./?installed=1',true,303);
         exit;
       } catch(Throwable $e) {
         error_log('HelixPanel hosting installer: '.get_class($e).' / '.substr($e->getMessage(),0,100));
         $errors[]='اتصال دیتابیس یا ذخیره تنظیمات ناموفق بود. مشخصات و دسترسی نوشتن را بررسی کنید.';
       }
     }
   }
 }
}
?>
<!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#101c2e"><meta name="robots" content="noindex,nofollow">
<title>نصب سریع HelixPanel Master</title>
<link rel="icon" type="image/webp" href="data:image/webp;base64,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">
<link rel="stylesheet" href="assets/master.css">
<link rel="stylesheet" href="assets/setup.css">
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
.setup-body{min-height:100vh;padding:38px 14px;background:radial-gradient(circle at 13% 10%,#22477555,transparent 38%),radial-gradient(circle at 85% 95%,#08637c25,transparent 35%),#0b1220}
.setup-wrap{max-width:800px;margin:0 auto}.setup-header{display:flex;align-items:center;gap:20px;margin-bottom:28px}.setup-header>img{object-fit:contain;background:#edf8ff;border-radius:18px;padding:6px;box-shadow:0 10px 30px #1264a542}.setup-header h1{margin:0;font-size:29px}.setup-header p{margin:5px 0;color:#a5b9d3}.setup-overline{font:700 11px Arial;letter-spacing:1.5px;color:#52cfe9}
.setup-progress,.setup-benefits{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:22px}.setup-progress span,.setup-benefits span{background:#14263d;border:1px solid #304967;color:#9bb7d0;border-radius:999px;padding:7px 13px;font-size:12px}.setup-progress .done{color:#78e2db;border-color:#2a7478}.setup-benefits span{background:#143c4055;color:#9cefe5;border-color:#346b6a}
.setup-note,.setup-form{border-radius:19px;background:linear-gradient(135deg,#16273e,#101a2a);border:1px solid #30445d;padding:25px;margin-bottom:20px;box-shadow:0 15px 30px #020a132a}
.setup-note strong{font-size:16px}.setup-note p{color:#acbdd2;margin:9px 0}.setup-note code{display:block;overflow-x:auto;border:1px solid #355270;background:#080f20;border-radius:9px;padding:12px;color:#72d6ec;direction:ltr;text-align:left}.setup-note small{display:block;color:#91a9c0;margin-top:10px}
.setup-form h2{color:#e9f6ff;font-size:17px;border-top:1px solid #2e3c50;padding-top:22px;margin-top:25px}.setup-form h2:first-of-type{border:0;padding:0;margin-top:0}.setup-form label{margin:12px 0 7px}.setup-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.setup-form input{background:#091728;border:1px solid #325170;border-radius:10px}.setup-form input:focus{border-color:#45cde7}.setup-submit{width:100%;margin-top:26px;padding:14px}.setup-footer{color:#9dafc5;display:block;text-align:center;margin-top:18px}@media(max-width:630px){.setup-grid{grid-template-columns:1fr}.setup-header h1{font-size:24px}.setup-note,.setup-form{padding:17px}.setup-body{padding:25px 12px}}
</style>

</head>
<body class="setup-body">
<main class="setup-wrap">
<header class="setup-header">
<img src="data:image/webp;base64,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" width="66" height="66" alt="HelixPanel">
<div><small class="setup-overline">HELIXPANEL · HOSTING EDITION</small><h1>راه‌اندازی مستر پنل</h1>
<p>نصب ایمن روی cPanel و DirectAdmin، بدون SSH و لایسنس خرید</p></div>
</header>
<div class="setup-progress"><span class="done">۱. بررسی هاست</span><span class="done">۲. رمز راه‌اندازی</span><span>۳. دیتابیس و مدیر</span></div>
<section class="setup-benefits"><span>✓ نسخه رایگان دائمی</span><span>✓ ۱ نود قابل اتصال</span><span>✓ ۲۵ کاربر</span><span>✓ ترافیک فقط از نودها</span></section>
<?php foreach($errors as $message): ?>
<div class="error" role="alert"><?= hpEsc($message) ?></div>
<?php endforeach; ?>
<div class="setup-note">
<strong>🔐 احراز هویت مالک هاست</strong>
<p>اولین بازدید، یک رمز تصادفی در این مسیر خصوصی ساخته است. آن فایل فقط از طریق File Manager هاست قابل مشاهده است؛ محتویاتش را کپی کن و در فرم وارد کن:</p>
<code dir="ltr"><?= hpEsc($keyFile) ?></code>
<small>این رمز در سایت یا سورس HTML نمایش داده نمی‌شود و پس از نصب حذف خواهد شد.</small>
</div>
<form class="setup-form" method="post" autocomplete="off">
<input type="hidden" name="csrf" value="<?= hpEsc($_SESSION['setup_csrf']) ?>">
<h2>۱. تایید دسترسی هاست</h2>
<label for="setup-key">کد خصوصی setup.key</label>
<input id="setup-key" name="setup_key" type="password" required minlength="64" maxlength="64" autocomplete="off" placeholder="کد ۶۴ کاراکتری از File Manager">
<h2>۲. اتصال به MySQL</h2>
<div class="setup-grid"><div><label for="dbhost">میزبان دیتابیس</label><input id="dbhost" name="db_host" value="<?= hpEsc($_POST['db_host']??'localhost') ?>" required></div>
<div><label for="dbport">پورت</label><input id="dbport" name="db_port" type="number" value="3306" min="1" max="65535" required></div>
<div><label for="dbname">نام دیتابیس</label><input id="dbname" name="db_name" value="<?= hpEsc($_POST['db_name']??'') ?>" required></div>
<div><label for="dbuser">نام کاربری دیتابیس</label><input id="dbuser" name="db_user" value="<?= hpEsc($_POST['db_user']??'') ?>" required></div></div>
<label for="dbpass">رمز دیتابیس</label><input id="dbpass" type="password" name="db_password" required autocomplete="new-password">
<h2>۳. حساب مدیر</h2>
<div class="setup-grid"><div><label for="adminpass">رمز مدیر (حداقل ۱۴ کاراکتر)</label><input id="adminpass" type="password" name="admin_password" minlength="14" required autocomplete="new-password"></div>
<div><label for="adminpass2">تکرار رمز مدیر</label><input id="adminpass2" type="password" name="confirm_password" minlength="14" required autocomplete="new-password"></div></div>
<button class="primary setup-submit" type="submit">نصب و فعال‌سازی رایگان ←</button>
<small class="setup-footer">تمام رمزها خارج از public_html ذخیره می‌شوند. پس از نصب، صفحه راه‌اندازی قفل می‌شود.</small>
</form>
</main></body></html>

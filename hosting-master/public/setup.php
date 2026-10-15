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
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
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
           'license_key'=>'','license_public_key'=>'',
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
<link rel="icon" type="image/webp" href="assets/logo-mark.webp">
<link rel="stylesheet" href="assets/master.css">
<link rel="stylesheet" href="assets/setup.css">
</head>
<body class="setup-body">
<main class="setup-wrap">
<header class="setup-header">
<img src="assets/logo-mark.webp" width="66" height="66" alt="HelixPanel">
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
